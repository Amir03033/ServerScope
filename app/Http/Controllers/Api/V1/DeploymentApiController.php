<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Services\Dependencies\ResourceCatalog;
use App\Services\Deployments\DeployJudge;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Deployments over the REST API. Recording needs a token with the write
 * ability (and supports Idempotency-Key); the token's team owns the
 * deployment and every linked resource must belong to that team.
 */
class DeploymentApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'application' => ['nullable', 'string', 'max:100'],
            'environment' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $page = Deployment::query()
            ->when($v['application'] ?? null, fn ($q, $a) => $q->where('application', $a))
            ->when($v['environment'] ?? null, fn ($q, $e) => $q->where('environment', $e))
            ->latest('deployed_at')->latest('id')
            ->paginate($v['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Deployment $d) => $this->present($d))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Deployment $deployment, DeployJudge $judge): JsonResponse
    {
        $analysis = $judge->analyse($deployment);

        return response()->json(['data' => [
            ...$this->present($deployment),
            'analysis' => [
                'status' => $analysis['status'],
                'window_minutes' => $analysis['window_minutes'],
                'ready_at' => isset($analysis['ready_at']) ? $analysis['ready_at']->toIso8601String() : null,
                'checks' => $analysis['checks'],
                'new_incidents' => array_map(fn ($i) => ['id' => $i['incident']->id, 'title' => $i['incident']->title, 'resource' => $i['resource'], 'minutes_after' => $i['minutes']], $analysis['incidents']),
                'overlapping_deployments' => $analysis['overlaps']->pluck('id')->values(),
                'note' => __('deployments.caveat'),
            ],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'application' => ['required', 'string', 'max:100'],
            'environment' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'version' => ['nullable', 'string', 'max:100'],
            'commit_sha' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{7,64}$/'],
            'deployed_at' => ['nullable', 'date', 'after_or_equal:'.now()->subDays(30)->toIso8601String(), 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'source' => ['nullable', 'string', 'max:50'],
            'actor' => ['nullable', 'string', 'max:100'],
            'url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'external_id' => ['nullable', 'string', 'max:191', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'resources' => ['required', 'array', 'min:1', 'max:50'],
            'resources.*.type' => ['required', 'string', Rule::in(array_keys(ResourceCatalog::TYPES))],
            'resources.*.id' => ['required', 'integer', 'min:1'],
        ]);

        // A retry of the same CI run (without Idempotency-Key) is not a new deployment.
        if (isset($v['external_id']) && ($existing = Deployment::query()->where('external_id', $v['external_id'])->first())) {
            return response()->json(['data' => $this->present($existing)], 200);
        }

        $refs = [];

        foreach ($v['resources'] as $index => $ref) {
            // Unknown and other teams' resources look the same.
            if (ResourceCatalog::find($ref['type'], (int) $ref['id'], Tenant::id()) === null) {
                return response()->json(['message' => 'Onbekende resource.', 'errors' => ["resources.{$index}.id" => ['Onbekende resource.']]], 422);
            }

            $refs[$ref['type'].':'.$ref['id']] = ['resource_type' => $ref['type'], 'resource_id' => (int) $ref['id']];
        }

        $deployment = DB::transaction(function () use ($v, $refs, $request) {
            $deployment = new Deployment([
                ...collect($v)->except(['resources', 'deployed_at'])->all(),
                'environment' => $v['environment'] ?? 'production',
                'commit_sha' => isset($v['commit_sha']) ? strtolower($v['commit_sha']) : null,
                'deployed_at' => isset($v['deployed_at']) ? Carbon::parse($v['deployed_at'])->utc() : now(),
            ]);
            $deployment->forceFill(['created_by' => $request->user()?->id, 'api_token_id' => $request->attributes->get('apiToken')?->id])->save();
            DB::table('deployment_resources')->insert(array_map(fn ($r) => [...$r, 'deployment_id' => $deployment->id], array_values($refs)));

            return $deployment;
        });

        Audit::log('deployment.recorded', "Deployment {$deployment->label()} vastgelegd via de API", $deployment);

        return response()->json(['data' => $this->present($deployment)], 201);
    }

    /** @return array<string, mixed> */
    private function present(Deployment $d): array
    {
        return [
            'id' => $d->id,
            'application' => $d->application,
            'environment' => $d->environment,
            'version' => $d->version,
            'commit_sha' => $d->commit_sha,
            'deployed_at' => $d->deployed_at->toIso8601String(),
            'source' => $d->source,
            'actor' => $d->actor,
            'url' => $d->url,
            'external_id' => $d->external_id,
            'resources' => $d->resourceRefs(),
            'links' => ['html' => route('deployments.show', $d)],
        ];
    }
}
