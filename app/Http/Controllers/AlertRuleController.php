<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AlertRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AlertRuleController extends Controller
{
    public function index(): View
    {
        $this->authorizeAdmin();

        $rules = AlertRule::query()
            ->withCount('alerts')
            ->orderBy('name')
            ->paginate(15);

        return view('alert-rules.index', compact('rules'));
    }

    public function create(): View
    {
        $this->authorizeAdmin();

        return view('alert-rules.create', [
            'rule' => new AlertRule(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeAdmin();

        $validated = $this->validateRule($request);

        AlertRule::create($validated);

        return redirect()
            ->route('alert-rules.index')
            ->with('success', __('alert_rules.flash.created'));
    }

    public function edit(AlertRule $alertRule): View
    {
        $this->authorizeAdmin();

        return view('alert-rules.edit', [
            'rule' => $alertRule,
        ]);
    }

    public function update(
        Request $request,
        AlertRule $alertRule,
    ): RedirectResponse {
        $this->authorizeAdmin();

        $validated = $this->validateRule($request);

        DB::transaction(function () use ($alertRule, $validated): void {
            $lockedRule = AlertRule::query()
                ->whereKey($alertRule->id)
                ->lockForUpdate()
                ->firstOrFail();

            $wasEnabled = (bool) $lockedRule->is_enabled;
            $willBeEnabled = (bool) $validated['is_enabled'];

            $lockedRule->update($validated);

            if ($wasEnabled && ! $willBeEnabled) {
                // Per-model updates so the linked incidents are resolved too.
                Alert::query()
                    ->where('alert_rule_id', $lockedRule->id)
                    ->where('status', 'active')
                    ->get()
                    ->each(fn (Alert $alert) => $alert->update([
                        'status' => 'disabled',
                        'resolved_at' => now(),
                    ]));
            }
        });

        return redirect()
            ->route('alert-rules.index')
            ->with(
                'success',
                $validated['is_enabled']
                    ? __('alert_rules.flash.updated_enabled')
                    : __('alert_rules.flash.updated_disabled'),
            );
    }

    public function destroy(AlertRule $alertRule): RedirectResponse
    {
        $this->authorizeAdmin();

        if ($alertRule->alerts()->where('status', 'active')->exists()) {
            return redirect()
                ->route('alert-rules.index')
                ->with('error', __('alert_rules.flash.has_history'));
        }

        // The confirmation promises the history goes with the rule.
        DB::transaction(function () use ($alertRule): void {
            $alertRule->alerts()->delete();
            $alertRule->delete();
        });

        return redirect()
            ->route('alert-rules.index')
            ->with('success', __('alert_rules.flash.deleted'));
    }

    private function authorizeAdmin(): void
    {
        abort_unless(
            \Illuminate\Support\Facades\Gate::allows('admin'),
            403,
            __('alert_rules.forbidden'),
        );
    }

    private function validateRule(Request $request): array
    {
        $isAgentOffline = $request->input('metric') === AlertRule::METRIC_AGENT_OFFLINE;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'metric' => [
                'required',
                Rule::in(array_keys(AlertRule::METRIC_LABELS)),
            ],
            // Percentages for resource metrics; minutes without a
            // heartbeat for agent_offline.
            'threshold' => $isAgentOffline
                ? ['required', 'integer', 'between:2,1440']
                : ['required', 'numeric', 'between:0,100'],
            'operator' => [
                'required',
                Rule::in(['>=', '>', '<=', '<']),
            ],
            'duration_minutes' => [
                'required',
                'integer',
                'between:1,1440',
            ],
            'is_enabled' => ['required', 'boolean'],
            'recovery_samples' => ['sometimes', 'integer', 'between:1,30'],
            'severity' => ['sometimes', Rule::in(\App\Support\Severity::ALL)],
        ]);

        $validated['severity'] ??= $isAgentOffline ? 'critical' : 'warning';
        $validated['recovery_samples'] ??= 1;

        if ($isAgentOffline) {
            // The threshold itself is the duration of silence.
            $validated['operator'] = '>=';
            $validated['duration_minutes'] = 1;
        }

        return $validated;
    }
}
