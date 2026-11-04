<?php

namespace App\Http\Controllers;

use App\Models\DeployWatch;
use App\Models\Domain;
use App\Models\GithubConnection;
use App\Models\Server;
use App\Services\Dependencies\ResourceCatalog;
use App\Support\Audit;
use App\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Where automatic deployments come from: the checkouts agents watch, and
 * connected GitHub repositories. Everyone in the team sees the page; team
 * administrators connect and disconnect repositories. A webhook secret is
 * shown once (flashed), never again.
 */
class DeploySourceController extends Controller
{
    public function index(): View
    {
        return view('deployments.sources', [
            'watches' => DeployWatch::query()->with('server:id,name')->orderBy('server_id')->orderBy('name')->get(),
            'connections' => GithubConnection::query()->orderBy('repository')->get(),
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
            'domains' => Domain::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $v = $request->validate([
            'repository' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', Rule::unique('github_connections')->where('team_id', Tenant::id())],
            'application' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'environment' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'deploy_on' => ['required', Rule::in(GithubConnection::DEPLOY_ON)],
            'branch' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'resources' => ['nullable', 'array', 'max:50'],
            'resources.*' => ['string', 'regex:/^(server|domain):\d+$/'],
        ], [], ['repository' => __('deploy_sources.github.repository')]);

        $refs = [];

        foreach ($v['resources'] ?? [] as $key) {
            [$type, $id] = explode(':', $key);

            // Unknown and other teams' resources are dropped the same way.
            if (ResourceCatalog::find($type, (int) $id, Tenant::id()) !== null) {
                $refs[$key] = ['type' => $type, 'id' => (int) $id];
            }
        }

        $secret = Str::random(40);
        $connection = new GithubConnection([...collect($v)->except('resources')->all(), 'resources' => array_values($refs)]);
        $connection->forceFill(['uuid' => (string) Str::uuid(), 'secret' => $secret, 'created_by' => $request->user()->id])->save();

        Audit::log('github.connected', "GitHub-repository {$connection->repository} gekoppeld", $connection);

        return redirect()->route('deploy-sources.index')->with('github_secret', ['id' => $connection->id, 'secret' => $secret])
            ->with('success', __('deploy_sources.github.connected', ['repository' => $connection->repository]));
    }

    public function renew(GithubConnection $connection): RedirectResponse
    {
        Gate::authorize('admin');

        $secret = Str::random(40);
        $connection->forceFill(['secret' => $secret])->save();
        Audit::log('github.secret_renewed', "Webhook-geheim van {$connection->repository} vernieuwd", $connection);

        return redirect()->route('deploy-sources.index')->with('github_secret', ['id' => $connection->id, 'secret' => $secret])
            ->with('success', __('deploy_sources.github.renewed'));
    }

    public function destroy(GithubConnection $connection): RedirectResponse
    {
        Gate::authorize('admin');

        Audit::log('github.disconnected', "GitHub-repository {$connection->repository} ontkoppeld", $connection);
        $connection->delete();

        return redirect()->route('deploy-sources.index')->with('success', __('deploy_sources.github.disconnected'));
    }
}
