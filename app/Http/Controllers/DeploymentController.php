<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Services\Dependencies\ResourceCatalog;
use App\Services\Deployments\DeployJudge;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Deployment history and the before/after comparison per deployment.
 * Every team member may view them (like incidents); deployments are only
 * recorded through the API. Route model binding is tenant-scoped.
 */
class DeploymentController extends Controller
{
    public function index(Request $request): View
    {
        $v = $request->validate(['application' => ['nullable', 'string', 'max:100'], 'environment' => ['nullable', 'string', 'max:50']]);

        $deployments = Deployment::query()
            ->when($v['application'] ?? null, fn ($q, $a) => $q->where('application', $a))
            ->when($v['environment'] ?? null, fn ($q, $e) => $q->where('environment', $e))
            ->latest('deployed_at')->latest('id')
            ->paginate(25)->withQueryString();

        return view('deployments.index', [
            'deployments' => $deployments,
            'applications' => Deployment::query()->distinct()->orderBy('application')->pluck('application'),
            'filters' => $v,
        ]);
    }

    public function show(Deployment $deployment, DeployJudge $judge): View
    {
        return view('deployments.show', [
            'deployment' => $deployment,
            'analysis' => $judge->analyse($deployment),
            'resourceUrl' => fn ($model) => ResourceCatalog::url($model),
        ]);
    }
}
