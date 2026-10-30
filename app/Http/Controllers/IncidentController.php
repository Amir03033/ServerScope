<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use App\Models\User;
use App\Rules\TeamMember;
use App\Tenancy\Tenant;
use App\Services\Incidents\CorrelationEngine;
use App\Services\Incidents\IncidentManager;
use App\Services\Incidents\IncidentMetrics;
use App\Services\Incidents\Signal;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Permissions: every signed-in user may view, comment, acknowledge,
 * investigate, resolve and (re)assign incidents; administrators may
 * additionally create manual incidents, close or reopen incidents and
 * change their severity.
 */
class IncidentController extends Controller
{
    public function __construct(
        private IncidentManager $incidents,
    ) {}

    public function index(Request $request, IncidentMetrics $metrics): View
    {
        $filters = $request->validate([
            'state' => ['nullable', Rule::in(['active', 'unacknowledged', 'resolved', 'closed', 'all'])],
            'severity' => ['nullable', Rule::in(Incident::SEVERITIES)],
            'source' => ['nullable', Rule::in(array_keys(Incident::SOURCE_LABELS))],
            'server' => ['nullable', 'integer'],
            'domain' => ['nullable', 'integer'],
            'assigned' => ['nullable', Rule::in(['me', 'none'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $state = $filters['state'] ?? 'active';
        $query = Incident::query()->with(['subject', 'assignee:id,name'])->withCount('children');

        match ($state) {
            'active' => $query->active(),
            'unacknowledged' => $query->where('status', 'open')->whereNull('acknowledged_at'),
            'resolved' => $query->where('status', 'resolved'),
            'closed' => $query->where('status', 'closed'),
            default => null,
        };

        $query->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($filters['server'] ?? null, fn ($q, $v) => $q->where('subject_type', 'server')->where('subject_id', $v))
            ->when($filters['domain'] ?? null, fn ($q, $v) => $q->where('subject_type', 'domain')->where('subject_id', $v))
            ->when(($filters['assigned'] ?? null) === 'me', fn ($q) => $q->where('assigned_to', $request->user()->id))
            ->when(($filters['assigned'] ?? null) === 'none', fn ($q) => $q->whereNull('assigned_to'))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('title', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $v).'%'));

        $incidents = $query
            ->orderByRaw("CASE status WHEN 'open' THEN 0 WHEN 'acknowledged' THEN 1 WHEN 'investigating' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->latest('opened_at')
            ->paginate(20)
            ->withQueryString();

        $counts = [
            'active' => Incident::query()->active()->count(),
            'critical' => Incident::query()->active()->where('severity', 'critical')->count(),
            'unacknowledged' => Incident::query()->where('status', 'open')->whereNull('acknowledged_at')->count(),
            'resolved_7d' => Incident::query()->whereIn('status', ['resolved', 'closed'])->where('resolved_at', '>=', now()->subDays(7))->count(),
        ];

        return view('incidents.index', [
            'incidents' => $incidents,
            'filters' => $filters,
            'state' => $state,
            'counts' => $counts,
            'summary' => $metrics->summary(now()->subDays(30), now()),
            'perDay' => $metrics->perDay(30),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('admin');

        return view('incidents.create', [
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
            'domains' => Domain::query()->orderBy('name')->get(['id', 'name']),
            'users' => TeamMember::options(Tenant::id()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'severity' => ['required', Rule::in(Incident::SEVERITIES)],
            'subject' => ['nullable', 'string', 'regex:/^(server|domain):\d+$/'],
            'assigned_to' => ['nullable', 'integer', new TeamMember(Tenant::id())],
        ]);

        $subject = null;

        if (! empty($validated['subject'])) {
            [$type, $id] = explode(':', $validated['subject']);
            $subject = ($type === 'server' ? Server::query() : Domain::query())->findOrFail((int) $id);
        }

        $incident = $this->incidents->trigger(new Signal(
            fingerprint: 'manual:'.Str::uuid(),
            source: 'manual',
            severity: $validated['severity'],
            title: $validated['title'],
            description: $validated['description'] ?? null,
            subject: $subject,
            context: ['created_by' => $request->user()->id],
            teamId: Tenant::id(),
        ));

        if (! empty($validated['assigned_to'])) {
            $this->incidents->assign($incident, User::query()->find($validated['assigned_to']), $request->user());
        }

        Audit::log('incident.created', "Handmatig incident aangemaakt: {$incident->title}", $incident);

        return redirect()->route('incidents.show', $incident)->with('success', __('incidents.flash.created'));
    }

    public function show(Incident $incident): View
    {
        $incident->load(['events.user:id,name', 'assignee:id,name', 'acknowledger:id,name', 'resolver:id,name', 'alerts.server:id,name', 'parent', 'children.subject', 'impacts.resource']);

        $related = $incident->subject_type
            ? Incident::query()
                ->where('subject_type', $incident->subject_type)
                ->where('subject_id', $incident->subject_id)
                ->whereKeyNot($incident->id)
                ->latest('opened_at')
                ->limit(5)
                ->get()
            : collect();

        $checks = $incident->subject instanceof Domain
            ? $incident->subject->checks()
                ->where('checked_at', '>=', $incident->opened_at->copy()->subMinutes(30))
                ->when($incident->resolved_at, fn ($q) => $q->where('checked_at', '<=', $incident->resolved_at->copy()->addMinutes(30)))
                ->where('success', false)
                ->latest('checked_at')
                ->limit(20)
                ->get()
            : collect();

        // Recurrence: how often the same underlying problem occurred.
        $recurrence = [
            'last_30d' => Incident::query()->where('fingerprint', $incident->fingerprint)->where('opened_at', '>=', now()->subDays(30))->count(),
            'total' => Incident::query()->where('fingerprint', $incident->fingerprint)->count(),
            'reopened' => $incident->reopened_count,
        ];

        return view('incidents.show', [
            'incident' => $incident,
            'recurrence' => $recurrence,
            'related' => $related,
            'checks' => $checks,
            'users' => TeamMember::options($incident->team_id),
        ]);
    }

    public function transition(Request $request, Incident $incident): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(Incident::STATUSES)],
            // A manual resolution records what the cause and fix were.
            'notes' => ['nullable', 'required_if:status,resolved', 'string', 'min:3', 'max:5000'],
        ], [
            'notes.required_if' => __('incidents.flash.notes_required'),
        ]);

        if (in_array($validated['status'], ['closed', 'open'], true)) {
            Gate::authorize('admin');
        }

        try {
            $this->incidents->transition($incident, $validated['status'], $request->user(), $validated['notes'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $label = Lang::has('incidents.flash.transitions.'.$validated['status']) ? __('incidents.flash.transitions.'.$validated['status']) : $validated['status'];

        return back()->with('success', __('incidents.flash.transitioned', ['status' => $label]));
    }

    public function assign(Request $request, Incident $incident): RedirectResponse
    {
        $validated = $request->validate(['assigned_to' => ['nullable', 'integer', new TeamMember($incident->team_id)]]);

        $assignee = isset($validated['assigned_to']) ? User::query()->find($validated['assigned_to']) : null;
        $this->incidents->assign($incident, $assignee, $request->user());

        return back()->with('success', $assignee ? __('incidents.flash.assigned', ['name' => $assignee->name]) : __('incidents.flash.unassigned'));
    }

    public function severity(Request $request, Incident $incident): RedirectResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate(['severity' => ['required', Rule::in(Incident::SEVERITIES)]]);
        $this->incidents->changeSeverity($incident, $validated['severity'], $request->user());

        return back()->with('success', __('incidents.flash.severity'));
    }

    /** Undo an automatic grouping (admin). */
    public function detach(Request $request, Incident $incident, CorrelationEngine $correlation): RedirectResponse
    {
        Gate::authorize('admin');

        if ($incident->parent_id === null) {
            return back()->with('error', __('incidents.flash.not_grouped'));
        }

        $correlation->detach($incident, $request->user());
        Audit::log('incident.detached', "Incident #{$incident->id} losgekoppeld van zijn hoofdincident", $incident);

        return back()->with('success', __('incidents.flash.detached'));
    }

    public function comment(Request $request, Incident $incident): RedirectResponse
    {
        $validated = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $this->incidents->comment($incident, $request->user(), $validated['message']);

        return back()->with('success', __('incidents.flash.commented'));
    }
}
