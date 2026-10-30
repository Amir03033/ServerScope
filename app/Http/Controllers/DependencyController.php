<?php

namespace App\Http\Controllers;

use App\Models\ResourceDependency;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Dependencies\ResourceCatalog;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Confirmed dependencies between monitored resources. Everyone can view
 * them; administrators manage them.
 */
class DependencyController extends Controller
{
    public function index(): View
    {
        $dependencies = ResourceDependency::query()
            ->with(['dependent', 'dependency', 'creator:id,name'])
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('dependencies.index', [
            'dependencies' => $dependencies,
            'options' => Gate::allows('admin') ? ResourceCatalog::options() : [],
        ]);
    }

    public function store(Request $request, DependencyGraph $graph): RedirectResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'dependent' => ['required', 'string', 'regex:/^[a-z_]+:\d+$/'],
            'dependency' => ['required', 'string', 'regex:/^[a-z_]+:\d+$/', 'different:dependent'],
            'label' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'dependency.different' => __('dependencies.validation.self'),
        ], [
            'dependent' => __('dependencies.attributes.dependent'),
            'dependency' => __('dependencies.attributes.dependency'),
        ]);

        // Both ends must belong to the current team.
        $dependent = ResourceCatalog::fromKey($validated['dependent'], Tenant::id());
        $dependency = ResourceCatalog::fromKey($validated['dependency'], Tenant::id());

        if ($dependent === null || $dependency === null) {
            throw ValidationException::withMessages(['dependent' => __('dependencies.validation.unknown')]);
        }

        $types = [$dependent->getMorphClass(), (int) $dependent->getKey(), $dependency->getMorphClass(), (int) $dependency->getKey()];

        if ($graph->wouldCreateCycle(...$types, teamId: Tenant::id())) {
            throw ValidationException::withMessages(['dependency' => __('dependencies.validation.cycle')]);
        }

        $row = ResourceDependency::query()->firstOrCreate(
            ['dependent_type' => $types[0], 'dependent_id' => $types[1], 'dependency_type' => $types[2], 'dependency_id' => $types[3]],
            ['label' => $validated['label'] ?? null, 'notes' => $validated['notes'] ?? null, 'created_by' => $request->user()->id],
        );

        if (! $row->wasRecentlyCreated) {
            return back()->with('error', __('dependencies.flash.exists'));
        }

        Audit::log('dependency.created', ResourceCatalog::name($dependent).' hangt af van '.ResourceCatalog::name($dependency), $row);

        return back()->with('success', __('dependencies.flash.created', ['dependent' => ResourceCatalog::name($dependent), 'dependency' => ResourceCatalog::name($dependency)]));
    }

    public function destroy(ResourceDependency $dependency): RedirectResponse
    {
        Gate::authorize('admin');

        $description = ResourceCatalog::name($dependency->dependent).' hangt af van '.ResourceCatalog::name($dependency->dependency);
        $dependency->delete();
        Audit::log('dependency.deleted', "Afhankelijkheid verwijderd: {$description}");

        return back()->with('success', __('dependencies.flash.deleted'));
    }
}
