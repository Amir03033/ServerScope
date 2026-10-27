<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PageRequest;
use App\Http\Resources\PageResource;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\MonitoredPage;
use App\Models\Prediction;
use App\Models\ResourceDependency;
use App\Models\Server;
use App\Models\ServiceCheck;
use App\Models\User;
use App\Monitoring\Net\BlockedTargetException;
use App\Monitoring\Net\TargetGuard;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Dependencies\ResourceCatalog;
use App\Services\Incidents\IncidentManager;
use App\Services\Websites\DiscoveryLauncher;
use App\Services\Websites\PageRegistrar;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * API v1 endpoints for website pages, discovery, dependencies, services
 * and predictions. Same permissions as the web interface: every token
 * can read; configuration changes need the write ability AND an
 * administrator as token owner.
 */
class MonitoringApiController extends Controller
{
    // ---------------- Pages ----------------

    public function pages(Request $request, Domain $domain): AnonymousResourceCollection
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::in(['up', 'degraded', 'down', 'unknown', 'paused', 'pending', 'rejected'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = $domain->pages()->orderBy('url');

        match ($v['status'] ?? null) {
            null => null,
            'pending', 'rejected' => $query->where('approval_status', $v['status']),
            'paused' => $query->where('approval_status', 'approved')->where('is_active', false),
            default => $query->monitored()->where('status', $v['status']),
        };

        return PageResource::collection($query->paginate($v['per_page'] ?? 25)->withQueryString());
    }

    public function page(MonitoredPage $page): PageResource
    {
        return new PageResource($page->load('domain'));
    }

    public function pageChecks(Request $request, MonitoredPage $page): JsonResponse
    {
        $v = $request->validate(['result' => ['nullable', Rule::in(['up', 'degraded', 'down'])], 'per_page' => ['nullable', 'integer', 'between:1,100']]);

        $checks = $page->checks()->when($v['result'] ?? null, fn ($q, $r) => $q->where('result', $r))
            ->latest('checked_at')->latest('id')->paginate($v['per_page'] ?? 50)->withQueryString();

        return $this->paginated($checks, fn ($c) => [
            'result' => $c->result, 'success' => $c->success, 'failure_type' => $c->failure_type, 'http_status_code' => $c->http_status_code,
            'response_time_ms' => $c->response_time_ms, 'message' => $c->message, 'content_changed' => $c->content_changed,
            'final_url' => $c->details['final_url'] ?? null, 'redirects' => $c->details['redirects'] ?? [], 'checked_at' => $c->checked_at->toIso8601String(),
        ]);
    }

    public function storePages(PageRequest $request, Domain $domain, PageRegistrar $registrar): JsonResponse
    {
        $result = $registrar->add($domain, $request->normalizedUrls, $request->pageData());
        Audit::log('website.pages_added', count($result['added'])." pagina('s) toegevoegd via de API aan {$domain->name}", $domain);

        return response()->json([
            'data' => PageResource::collection(collect($result['added'])->map->fresh())->resolve(),
            'meta' => ['added' => count($result['added']), 'duplicates' => $result['duplicates'], 'limit_reached' => $result['limit_reached']],
        ], count($result['added']) > 0 ? 201 : 200);
    }

    public function updatePage(PageRequest $request, MonitoredPage $page): PageResource
    {
        $url = $request->normalizedUrls[0];
        $page->fill($request->pageData());

        if ($url !== $page->url) {
            $page->forceFill(['url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), 'status' => 'unknown', 'consecutive_failures' => 0, 'consecutive_successes' => 0, 'content_fingerprint' => null, 'content_snapshot' => null, 'header_snapshot' => null, 'failing_since' => null]);
        }

        $page->save();
        Audit::log('website.page_updated', "Pagina {$page->url} bijgewerkt via de API", $page);

        return new PageResource($page->fresh());
    }

    public function destroyPage(MonitoredPage $page): JsonResponse
    {
        Gate::authorize('admin');

        PageRegistrar::delete($page);
        Audit::log('website.page_deleted', "Pagina {$page->url} verwijderd via de API", $page->domain);

        return response()->json(null, 204);
    }

    public function pageState(Request $request, MonitoredPage $page, string $action): PageResource|JsonResponse
    {
        Gate::authorize('admin');

        // Discovered URLs are validated again before monitoring starts.
        if ($action === 'approve') {
            try {
                app(TargetGuard::class)->resolve($page->url);
            } catch (BlockedTargetException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        }

        match ($action) {
            'pause' => $page->forceFill(['is_active' => false, 'paused_at' => now()])->save(),
            'resume' => $page->forceFill(['is_active' => true, 'paused_at' => null])->save(),
            'approve' => $page->forceFill(['approval_status' => 'approved'])->save(),
            'reject' => $page->forceFill(['approval_status' => 'rejected'])->save(),
        };

        if (in_array($action, ['resume', 'approve'], true)) {
            PageRegistrar::queueCheck($page);
        }

        Audit::log("website.page_{$action}", "Pagina {$page->url}: {$action} via de API", $page);

        return new PageResource($page->fresh());
    }

    // ---------------- Discovery ----------------

    public function discoveryRuns(Domain $domain): JsonResponse
    {
        $runs = $domain->discoveryRuns()->latest('id')->paginate(20);

        return $this->paginated($runs, fn ($r) => [
            'id' => $r->id, 'status' => $r->status, 'urls_found' => $r->urls_found, 'pages_added' => $r->pages_added,
            'pages_seen' => $r->pages_seen, 'urls_skipped' => $r->urls_skipped, 'requests_made' => $r->requests_made,
            'sources' => $r->sources ?? (object) [], 'errors' => $r->errors ?? [], 'settings' => $r->settings,
            'created_at' => $r->created_at->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(),
        ]);
    }

    public function startDiscovery(Request $request, Domain $domain, DiscoveryLauncher $launcher): JsonResponse
    {
        Gate::authorize('admin');

        $result = $launcher->start($domain, $request->user());

        if ($result['error'] !== null) {
            return response()->json(['message' => $result['error']], 409);
        }

        return response()->json(['data' => ['id' => $result['run']->id, 'status' => $result['run']->status]], 202);
    }

    // ---------------- Dependencies ----------------

    public function dependencies(): JsonResponse
    {
        $rows = ResourceDependency::query()->latest('id')->paginate(100);

        return $this->paginated($rows, fn ($d) => [
            'id' => $d->id,
            'dependent' => ['type' => $d->dependent_type, 'id' => $d->dependent_id, 'name' => ResourceCatalog::name($d->dependent)],
            'dependency' => ['type' => $d->dependency_type, 'id' => $d->dependency_id, 'name' => ResourceCatalog::name($d->dependency)],
            'label' => $d->label,
            'created_at' => $d->created_at->toIso8601String(),
        ]);
    }

    public function storeDependency(Request $request, DependencyGraph $graph): JsonResponse
    {
        Gate::authorize('admin');

        $v = $request->validate([
            'dependent_type' => ['required', Rule::in(array_keys(ResourceCatalog::TYPES))],
            'dependent_id' => ['required', 'integer'],
            'dependency_type' => ['required', Rule::in(array_keys(ResourceCatalog::TYPES))],
            'dependency_id' => ['required', 'integer'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        if (ResourceCatalog::find($v['dependent_type'], $v['dependent_id']) === null || ResourceCatalog::find($v['dependency_type'], $v['dependency_id']) === null) {
            return response()->json(['message' => 'Onbekende resource.', 'errors' => ['dependent_id' => ['Onbekende resource.']]], 422);
        }

        if ($graph->wouldCreateCycle($v['dependent_type'], $v['dependent_id'], $v['dependency_type'], $v['dependency_id'])) {
            return response()->json(['message' => 'Deze afhankelijkheid zou een kringverwijzing maken.', 'errors' => ['dependency_id' => ['Kringverwijzing.']]], 422);
        }

        $row = ResourceDependency::query()->firstOrCreate(
            collect($v)->only(['dependent_type', 'dependent_id', 'dependency_type', 'dependency_id'])->all(),
            ['label' => $v['label'] ?? null, 'created_by' => $request->user()->id],
        );

        if ($row->wasRecentlyCreated) {
            Audit::log('dependency.created', 'Afhankelijkheid vastgelegd via de API', $row);
        }

        return response()->json(['data' => ['id' => $row->id]], $row->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyDependency(ResourceDependency $dependency): JsonResponse
    {
        Gate::authorize('admin');

        $dependency->delete();
        Audit::log('dependency.deleted', 'Afhankelijkheid verwijderd via de API');

        return response()->json(null, 204);
    }

    // ---------------- Services and predictions ----------------

    public function services(Server $server): JsonResponse
    {
        $offline = in_array($server->displayStatus(), ['offline', 'pending'], true);

        return response()->json(['data' => $server->serviceChecks()->orderBy('name')->get()->map(fn (ServiceCheck $s) => [
            'id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'enabled' => $s->is_enabled,
            // Without agent data the last state is not current.
            'status' => $offline ? 'stale' : $s->status, 'reported_status' => $s->status, 'detail' => $s->state_detail,
            'last_reported_at' => $s->last_reported_at?->toIso8601String(), 'last_running_at' => $s->last_running_at?->toIso8601String(),
        ])]);
    }

    public function predictions(Request $request): JsonResponse
    {
        $v = $request->validate([
            'kind' => ['nullable', Rule::in(['anomaly', 'forecast'])],
            'status' => ['nullable', Rule::in(['active', 'resolved'])],
        ]);

        $rows = Prediction::query()
            ->where('status', $v['status'] ?? 'active')
            ->when($v['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->latest('detected_at')->paginate(50)->withQueryString();

        return $this->paginated($rows, fn (Prediction $p) => [
            'id' => $p->id, 'resource' => ['type' => $p->resource_type, 'id' => $p->resource_id], 'kind' => $p->kind,
            'metric' => $p->metric, 'dimension' => $p->dimension, 'status' => $p->status, 'severity' => $p->severity,
            'confidence' => $p->confidence, 'observed_value' => $p->observed_value, 'baseline_value' => $p->baseline_value,
            'score' => $p->score, 'slope_per_day' => $p->slope_per_day, 'r_squared' => $p->r_squared, 'threshold' => $p->threshold,
            'eta_at' => $p->eta_at?->toIso8601String(), 'eta_range' => [$p->eta_low_at?->toIso8601String(), $p->eta_high_at?->toIso8601String()],
            'horizon_days' => $p->horizon_days, 'window' => [$p->window_start?->toIso8601String(), $p->window_end?->toIso8601String()],
            'sample_count' => $p->sample_count, 'reason' => $p->reason, 'detected_at' => $p->detected_at->toIso8601String(),
        ]);
    }

    // ---------------- Incidents (extensions) ----------------

    public function assign(Request $request, Incident $incident, IncidentManager $manager): JsonResponse
    {
        $v = $request->validate(['user_id' => ['nullable', 'integer', 'exists:users,id']]);
        $manager->assign($incident, isset($v['user_id']) ? User::query()->find($v['user_id']) : null, $request->user());

        return response()->json(['data' => ['id' => $incident->id, 'assigned_to' => $incident->fresh()->assigned_to]]);
    }

    private function paginated(LengthAwarePaginator $paginator, callable $map): JsonResponse
    {
        return response()->json([
            'data' => collect($paginator->items())->map($map)->values(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
            'links' => ['next' => $paginator->nextPageUrl(), 'prev' => $paginator->previousPageUrl()],
        ]);
    }
}
