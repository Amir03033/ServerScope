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
            'dependency.different' => 'Een resource kan niet van zichzelf afhangen.',
        ], [
            'dependent' => 'afhankelijke resource',
            'dependency' => 'afhankelijkheid',
        ]);

        // Both ends must belong to the current team.
        $dependent = ResourceCatalog::fromKey($validated['dependent'], Tenant::id());
        $dependency = ResourceCatalog::fromKey($validated['dependency'], Tenant::id());

        if ($dependent === null || $dependency === null) {
            throw ValidationException::withMessages(['dependent' => 'Onbekende resource.']);
        }

        $types = [$dependent->getMorphClass(), (int) $dependent->getKey(), $dependency->getMorphClass(), (int) $dependency->getKey()];

        if ($graph->wouldCreateCycle(...$types, teamId: Tenant::id())) {
            throw ValidationException::withMessages(['dependency' => 'Deze afhankelijkheid zou een kringverwijzing maken.']);
        }

        $row = ResourceDependency::query()->firstOrCreate(
            ['dependent_type' => $types[0], 'dependent_id' => $types[1], 'dependency_type' => $types[2], 'dependency_id' => $types[3]],
            ['label' => $validated['label'] ?? null, 'notes' => $validated['notes'] ?? null, 'created_by' => $request->user()->id],
        );

        if (! $row->wasRecentlyCreated) {
            return back()->with('error', 'Deze afhankelijkheid bestaat al.');
        }

        Audit::log('dependency.created', ResourceCatalog::name($dependent).' hangt af van '.ResourceCatalog::name($dependency), $row);

        return back()->with('success', 'Afhankelijkheid vastgelegd. Incidenten van '.ResourceCatalog::name($dependent).' kunnen nu worden gegroepeerd onder storingen van '.ResourceCatalog::name($dependency).'.');
    }

    public function destroy(ResourceDependency $dependency): RedirectResponse
    {
        Gate::authorize('admin');

        $description = ResourceCatalog::name($dependency->dependent).' hangt af van '.ResourceCatalog::name($dependency->dependency);
        $dependency->delete();
        Audit::log('dependency.deleted', "Afhankelijkheid verwijderd: {$description}");

        return back()->with('success', 'Afhankelijkheid verwijderd.');
    }
}
