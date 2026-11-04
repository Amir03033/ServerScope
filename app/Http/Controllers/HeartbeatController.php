<?php

namespace App\Http\Controllers;

use App\Models\Heartbeat;
use App\Models\HeartbeatPing;
use App\Models\Incident;
use App\Models\Server;
use App\Services\Heartbeats\HeartbeatMonitor;
use App\Support\Audit;
use App\Support\Severity;
use App\Support\Time;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Scheduled jobs that report in on a ping URL (cron monitoring). Members read, administrators manage. */
class HeartbeatController extends Controller
{
    public function index(): View
    {
        $heartbeats = Heartbeat::query()->with('server')->orderByRaw("case status when 'down' then 0 when 'new' then 1 when 'up' then 2 else 3 end")->orderBy('name')->get();

        return view('heartbeats.index', [
            'heartbeats' => $heartbeats,
            'counts' => $heartbeats->countBy('status'),
            // The last runs of each job, oldest first, for the strip in the list.
            'recent' => HeartbeatPing::query()
                ->whereIn('heartbeat_id', $heartbeats->pluck('id'))->where('kind', '!=', 'start')
                ->where('received_at', '>=', now()->subDays(30))->orderByDesc('id')->limit(5000)
                ->get(['heartbeat_id', 'kind', 'received_at'])->groupBy('heartbeat_id')->map(fn ($pings) => $pings->take(20)->reverse()->values()),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('admin');

        return view('heartbeats.form', [
            'heartbeat' => new Heartbeat(['schedule_type' => Heartbeat::SCHEDULE_INTERVAL, 'period_minutes' => 60, 'grace_minutes' => 5, 'severity' => Severity::HIGH, 'timezone' => Time::zone(), 'server_id' => request()->integer('server') ?: null]),
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        if (Heartbeat::query()->count() >= Heartbeat::MAX_PER_TEAM) {
            return back()->withInput()->with('error', __('heartbeats.flash.limit', ['max' => Heartbeat::MAX_PER_TEAM]));
        }

        $heartbeat = new Heartbeat($this->validated($request));
        $heartbeat->forceFill(['token' => Heartbeat::newToken(), 'status' => 'new', 'created_by' => $request->user()->id])->save();
        Audit::log('heartbeat.created', "Geplande taak '{$heartbeat->name}' aangemaakt", $heartbeat);

        return redirect()->route('heartbeats.show', $heartbeat)->with('success', __('heartbeats.flash.created'));
    }

    public function show(Heartbeat $heartbeat): View
    {
        $pings = $heartbeat->pings()->orderByDesc('id')->paginate(30);
        $runs = $heartbeat->pings()->where('kind', '!=', 'start')->orderByDesc('id')->limit(60)->get(['kind', 'received_at', 'duration_ms', 'exit_code'])->reverse()->values();

        return view('heartbeats.show', [
            'heartbeat' => $heartbeat->load('server'),
            'pings' => $pings,
            'runs' => $runs,
            'incident' => Incident::query()->where('subject_type', $heartbeat->getMorphClass())->where('subject_id', $heartbeat->id)->latest('id')->first(),
        ]);
    }

    public function edit(Heartbeat $heartbeat): View
    {
        Gate::authorize('admin');

        return view('heartbeats.form', ['heartbeat' => $heartbeat, 'servers' => Server::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function update(Request $request, Heartbeat $heartbeat, HeartbeatMonitor $monitor): RedirectResponse
    {
        Gate::authorize('admin');

        $heartbeat->fill($this->validated($request))->save();
        $monitor->reschedule($heartbeat);
        Audit::log('heartbeat.updated', "Geplande taak '{$heartbeat->name}' bijgewerkt", $heartbeat);

        return redirect()->route('heartbeats.show', $heartbeat)->with('success', __('heartbeats.flash.saved'));
    }

    public function destroy(Heartbeat $heartbeat, HeartbeatMonitor $monitor): RedirectResponse
    {
        Gate::authorize('admin');

        $monitor->pause($heartbeat);
        $heartbeat->delete();
        Audit::log('heartbeat.deleted', "Geplande taak '{$heartbeat->name}' verwijderd");

        return redirect()->route('heartbeats.index')->with('success', __('heartbeats.flash.deleted'));
    }

    public function pause(Heartbeat $heartbeat, HeartbeatMonitor $monitor): RedirectResponse
    {
        Gate::authorize('admin');

        $heartbeat->isPaused() ? $monitor->resume($heartbeat) : $monitor->pause($heartbeat);
        Audit::log($heartbeat->isPaused() ? 'heartbeat.paused' : 'heartbeat.resumed', "Geplande taak '{$heartbeat->name}' ".($heartbeat->isPaused() ? 'gepauzeerd' : 'hervat'), $heartbeat);

        return back()->with('success', __($heartbeat->isPaused() ? 'heartbeats.flash.paused' : 'heartbeats.flash.resumed'));
    }

    public function rotate(Heartbeat $heartbeat): RedirectResponse
    {
        Gate::authorize('admin');

        $heartbeat->forceFill(['token' => Heartbeat::newToken()])->save();
        Audit::log('heartbeat.token_rotated', "Ping-URL van geplande taak '{$heartbeat->name}' vernieuwd", $heartbeat);

        return back()->with('success', __('heartbeats.flash.rotated'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'server_id' => ['nullable', 'integer', Rule::exists('servers', 'id')->where('team_id', Tenant::id())],
            'schedule_type' => ['required', Rule::in([Heartbeat::SCHEDULE_INTERVAL, Heartbeat::SCHEDULE_CRON])],
            'period_minutes' => ['required_if:schedule_type,interval', 'nullable', 'integer', 'min:1', 'max:43200'],
            'cron_expression' => ['required_if:schedule_type,cron', 'nullable', 'string', 'max:100'],
            'timezone' => ['required', 'timezone:all'],
            'grace_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'severity' => ['required', Rule::in(array_keys(Severity::LABELS))],
        ]);

        if ($data['schedule_type'] === Heartbeat::SCHEDULE_CRON) {
            $data['cron_expression'] = preg_replace('/\s+/', ' ', trim((string) $data['cron_expression']));

            if (! Heartbeat::validCron($data['cron_expression'])) {
                throw ValidationException::withMessages(['cron_expression' => __('heartbeats.flash.cron_invalid')]);
            }

            $data['period_minutes'] = null;
        } else {
            $data['cron_expression'] = null;
        }

        return $data;
    }
}
