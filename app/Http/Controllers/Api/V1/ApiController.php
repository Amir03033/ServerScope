<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DomainResource;
use App\Http\Resources\IncidentResource;
use App\Http\Resources\ServerResource;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\MetricSample;
use App\Models\Server;
use App\Services\Incidents\IncidentManager;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * ServerScope REST API v1. Responses: {"data": ...} with "links"/"meta"
 * for paginated lists; errors: {"message": ..., "errors": {...}}.
 */
class ApiController extends Controller
{
    public function servers(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::in(['online', 'offline', 'pending', 'maintenance'])],
            'environment' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = Server::query()->with('latestMetricSample')->orderBy('name')
            ->when($v['status'] ?? null, fn ($q, $s) => $q->withDisplayStatus($s))
            ->when($v['environment'] ?? null, fn ($q, $e) => $q->where('environment', $e));

        return ServerResource::collection($query->paginate($v['per_page'] ?? 25)->withQueryString());
    }

    public function server(Server $server): ServerResource
    {
        return new ServerResource($server->load('latestMetricSample'));
    }

    public function metrics(Request $request, Server $server): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
            'limit' => ['nullable', 'integer', 'between:1,1440'],
        ]);

        $samples = MetricSample::query()->where('server_id', $server->id)
            ->where('collected_at', '>=', isset($v['from']) ? Carbon::parse($v['from']) : now()->subHour())
            ->where('collected_at', '<=', isset($v['to']) ? Carbon::parse($v['to']) : now())
            ->orderByDesc('collected_at')
            ->limit($v['limit'] ?? 120)
            ->get()
            ->reverse()->values()
            ->map(fn (MetricSample $s) => [
                'collected_at' => $s->collected_at->toIso8601String(),
                'cpu_percent' => $s->cpu_percent,
                'memory_used_bytes' => $s->memory_used_bytes,
                'memory_total_bytes' => $s->memory_total_bytes,
                'load_1' => $s->load_1,
                'load_5' => $s->load_5,
                'load_15' => $s->load_15,
                'disks' => $s->disks,
                'network_rx_bytes' => $s->network_rx_bytes,
                'network_tx_bytes' => $s->network_tx_bytes,
            ]);

        return response()->json(['data' => $samples]);
    }

    public function domains(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'status' => ['nullable', Rule::in(['healthy', 'warning', 'critical', 'unknown'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = Domain::query()->orderBy('name')->when($v['status'] ?? null, fn ($q, $s) => $q->where('is_active', true)->where('status', $s));

        return DomainResource::collection($query->paginate($v['per_page'] ?? 25)->withQueryString());
    }

    public function domain(Domain $domain): DomainResource
    {
        return new DomainResource($domain);
    }

    public function domainChecks(Request $request, Domain $domain): JsonResponse
    {
        $v = $request->validate(['type' => ['nullable', Rule::in(['http', 'ssl', 'dns', 'expiry'])], 'per_page' => ['nullable', 'integer', 'between:1,100']]);

        $checks = $domain->checks()->when($v['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->latest('checked_at')->latest('id')->paginate($v['per_page'] ?? 50)->withQueryString();

        return response()->json([
            'data' => collect($checks->items())->map(fn ($c) => [
                'type' => $c->type, 'result' => $c->result, 'success' => $c->success,
                'response_time_ms' => $c->response_time_ms, 'http_status_code' => $c->http_status_code,
                'error_code' => $c->error_code, 'message' => $c->message, 'checked_at' => $c->checked_at->toIso8601String(),
            ]),
            'meta' => ['current_page' => $checks->currentPage(), 'last_page' => $checks->lastPage(), 'per_page' => $checks->perPage(), 'total' => $checks->total()],
            'links' => ['next' => $checks->nextPageUrl(), 'prev' => $checks->previousPageUrl()],
        ]);
    }

    public function incidents(Request $request): AnonymousResourceCollection
    {
        $v = $request->validate([
            'state' => ['nullable', Rule::in(['active', 'resolved', 'closed', 'all'])],
            'severity' => ['nullable', Rule::in(Incident::SEVERITIES)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = Incident::query()->with('subject')->latest('opened_at')
            ->when(($v['state'] ?? 'active') === 'active', fn ($q) => $q->active())
            ->when(in_array($v['state'] ?? null, ['resolved', 'closed'], true), fn ($q) => $q->where('status', $v['state']))
            ->when($v['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s));

        return IncidentResource::collection($query->paginate($v['per_page'] ?? 25)->withQueryString());
    }

    public function incident(Incident $incident): IncidentResource
    {
        return new IncidentResource($incident->load(['subject', 'events', 'impacts', 'children']));
    }

    public function transition(Request $request, Incident $incident, IncidentManager $manager, string $status): IncidentResource|JsonResponse
    {
        $v = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        try {
            $incident = $manager->transition($incident, $status, $request->user(), $v['notes'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new IncidentResource($incident->load(['subject', 'events']));
    }

    public function comment(Request $request, Incident $incident, IncidentManager $manager): JsonResponse
    {
        $v = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $event = $manager->comment($incident, $request->user(), $v['message']);

        return response()->json(['data' => ['type' => $event->type, 'message' => $event->message, 'created_at' => $event->created_at->toIso8601String()]], 201);
    }
}
