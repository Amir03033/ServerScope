<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\IncidentAnalysis;
use App\Models\Investigation;
use App\Models\MonitoredPage;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Services\Incidents\IncidentInvestigator;
use App\Services\Intelligence\Investigations;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * NEXUS Intelligence: deterministic investigations of an incident.
 * Every team member may view them (like the incident and Time Machine);
 * team administrators may request a new run (like AI analysis), which is
 * audited. Route binding is tenant-scoped; each action also compares the
 * team explicitly, so another team's investigation is a 404.
 */
class InvestigationController extends Controller
{
    /** The latest investigation of an incident, or its empty state. */
    public function latest(Request $request, Incident $incident): View
    {
        $this->sameTeam($incident->team_id);

        return $this->page($request, $incident, Investigations::latest($incident));
    }

    public function show(Request $request, Investigation $investigation): View
    {
        $this->sameTeam($investigation->team_id);
        $incident = Incident::query()->where('team_id', $investigation->team_id)->findOrFail($investigation->incident_id);

        return $this->page($request, $incident, $investigation);
    }

    public function store(Request $request, Incident $incident, Investigations $investigations): RedirectResponse
    {
        $this->sameTeam($incident->team_id);
        Gate::authorize('admin');

        $result = $investigations->request($incident, 'manual', $request->user());

        if (is_string($result)) {
            return back()->with('error', $result === 'cooldown' ? __('investigations.refused.cooldown') : __('investigations.refused.limit'));
        }

        Audit::log('incident.investigation_requested', "Onderzoek aangevraagd voor incident #{$incident->id}", $incident, ['investigation' => $result->id]);

        return redirect()->route('investigations.show', $result)->with('success', __($result->wasRecentlyCreated ? 'investigations.flash.queued' : 'investigations.flash.pending'));
    }

    /** The stored snapshot as JSON (machine-readable codes; for review and traceability). */
    public function export(Investigation $investigation): JsonResponse
    {
        $this->sameTeam($investigation->team_id);

        return response()->json(['data' => [
            'id' => $investigation->id,
            'incident_id' => $investigation->incident_id,
            'status' => $investigation->status,
            'trigger' => $investigation->trigger,
            'pipeline_version' => $investigation->pipeline_version,
            'as_of' => $investigation->as_of?->toIso8601String(),
            'input_digest' => $investigation->input_digest,
            'error_code' => $investigation->error_code,
            'started_at' => $investigation->started_at?->toIso8601String(),
            'finished_at' => $investigation->finished_at?->toIso8601String(),
            'summary' => $investigation->summary,
            'result' => $investigation->result,
        ]], 200, ['Content-Disposition' => 'attachment; filename="investigation-'.$investigation->id.'.json"'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function page(Request $request, Incident $incident, ?Investigation $investigation): View
    {
        $investigator = app(IncidentInvestigator::class);
        $filters = $request->validate([
            'resource' => ['nullable', 'string', 'max:40', 'regex:/^[a-z_]+:\d+$/'],
            'category' => ['nullable', Rule::in(['incident', 'change', 'anomaly', 'service', 'check', 'recovery', 'metric', 'coverage', 'checks', 'maintenance'])],
            'phase' => ['nullable', Rule::in(['before', 'during', 'after'])],
        ]);

        $history = Investigation::query()->where('team_id', $incident->team_id)->where('incident_id', $incident->id)
            ->latest('id')->limit(20)->get(['id', 'status', 'trigger', 'requested_by', 'pipeline_version', 'as_of', 'summary', 'input_digest', 'error_code', 'created_at', 'finished_at']);

        return view('investigations.show', [
            'incident' => $incident,
            'investigation' => $investigation,
            'history' => $history,
            'filters' => $filters,
            'current' => $investigation?->hasResult() ? $this->currentStatus((int) $incident->team_id, $investigation->result) : [],
            // The AI layer: the incident's latest analysis, and which runs were interpreted.
            'analysis' => IncidentAnalysis::query()->where('team_id', $incident->team_id)->where('incident_id', $incident->id)->latest('id')->first(),
            'aiByRun' => IncidentAnalysis::query()->where('team_id', $incident->team_id)->where('incident_id', $incident->id)->whereNotNull('investigation_id')
                ->latest('id')->get(['id', 'investigation_id', 'status'])->unique('investigation_id')->keyBy('investigation_id'),
            'aiAvailable' => $investigator->available(),
            'aiPlan' => IncidentInvestigator::allowedFor((int) $incident->team_id),
        ]);
    }

    /**
     * The status of the referenced resources now (not at the time of the
     * run), one query per type, within the team. Missing = deleted.
     *
     * @return array<string, string>
     */
    private function currentStatus(int $teamId, array $result): array
    {
        $ids = [];

        foreach ([...array_column($result['resources'] ?? [], 'key'), ...array_column($result['impact']['items'] ?? [], 'key')] as $key) {
            [$type, $id] = explode(':', $key);
            $ids[$type][] = (int) $id;
        }

        $status = [];
        $domains = fn () => Domain::query()->where('team_id', $teamId)->select('id');

        foreach ($ids as $type => $list) {
            $rows = match ($type) {
                'server' => Server::query()->where('team_id', $teamId)->whereIn('id', $list)->get(),
                'domain' => Domain::query()->where('team_id', $teamId)->whereIn('id', $list)->get(),
                'page' => MonitoredPage::query()->where('team_id', $teamId)->whereIn('domain_id', $domains())->whereIn('id', $list)->with('domain')->get(),
                'service' => ServiceCheck::query()->whereIn('server_id', Server::query()->where('team_id', $teamId)->select('id'))->whereIn('id', $list)->get(),
                default => collect(),
            };

            foreach ($rows as $model) {
                $status[$type.':'.$model->getKey()] = method_exists($model, 'displayStatus') ? $model->displayStatus() : ($model->is_enabled ? (string) $model->status : 'paused');
            }
        }

        return $status;
    }

    /** Defence in depth next to the tenant-scoped binding. */
    private function sameTeam(int|string|null $teamId): void
    {
        abort_unless(Tenant::check() && (int) $teamId === (int) Tenant::id(), 404);
    }
}
