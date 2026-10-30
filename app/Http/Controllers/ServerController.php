<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServerRequest;
use App\Models\Server;
use App\Services\AgentCredentialService;
use App\Services\ServerRegistrationService;
use App\Support\Audit;
use App\Support\Time;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ServerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['online', 'offline', 'pending', 'maintenance', 'deleted'])],
            'environment' => ['nullable', Rule::in(array_keys(Server::ENVIRONMENTS))],
            'sort' => ['nullable', Rule::in(['name', 'last_seen', 'environment'])],
        ]);

        $status = $filters['status'] ?? null;

        if ($status === 'deleted') {
            Gate::authorize('admin');
        }

        $query = Server::query()->with('latestMetricSample');

        if ($status === 'deleted') {
            $query->onlyTrashed();
        } elseif ($status !== null) {
            $query->withDisplayStatus($status);
        }

        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $filters['q']).'%';

            $query->where(fn ($q) => $q
                ->where('name', 'like', $term)
                ->orWhere('hostname', 'like', $term)
                ->orWhere('ip_address', 'like', $term));
        }

        if (! empty($filters['environment'])) {
            $query->where('environment', $filters['environment']);
        }

        match ($filters['sort'] ?? 'name') {
            'last_seen' => $query->orderByRaw('last_seen_at IS NULL')->orderByDesc('last_seen_at'),
            'environment' => $query->orderBy('environment')->orderBy('name'),
            default => $query->orderBy('name'),
        };

        $servers = $query->paginate(20)->withQueryString();

        $now = now();
        $counts = [
            'all' => Server::query()->count(),
            'online' => Server::query()->withDisplayStatus('online', $now)->count(),
            'offline' => Server::query()->withDisplayStatus('offline', $now)->count(),
            'pending' => Server::query()->withDisplayStatus('pending', $now)->count(),
            'maintenance' => Server::query()->withDisplayStatus('maintenance', $now)->count(),
            'deleted' => Server::onlyTrashed()->count(),
        ];

        return view('servers.index', compact('servers', 'filters', 'counts', 'status'));
    }

    public function create(): View
    {
        Gate::authorize('admin');

        return view('servers.create', ['server' => new Server(['environment' => 'production'])]);
    }

    public function store(ServerRequest $request, ServerRegistrationService $registration): RedirectResponse
    {
        $validated = $request->validated();
        $credentialName = $validated['credential_name'];
        unset($validated['credential_name']);

        $result = $registration->register($validated, $credentialName);

        Audit::log('server.created', "Server {$result['server']->name} geregistreerd", $result['server'], [
            'hostname' => $result['server']->hostname,
            'environment' => $result['server']->environment,
        ]);

        return redirect()
            ->route('servers.show', $result['server'])
            ->with('success', __('servers.flash.registered'))
            ->with('agent_token', $result['token']);
    }

    public function edit(Server $server): View
    {
        Gate::authorize('admin');

        return view('servers.edit', compact('server'));
    }

    public function update(ServerRequest $request, Server $server): RedirectResponse
    {
        $validated = $request->validated();

        $server->fill([
            ...collect($validated)->except(['maintenance_starts_at', 'maintenance_ends_at'])->all(),
            'maintenance_starts_at' => Time::parse($validated['maintenance_starts_at'] ?? null),
            'maintenance_ends_at' => Time::parse($validated['maintenance_ends_at'] ?? null),
        ]);

        if ($server->maintenance_starts_at === null) {
            $server->maintenance_reason = null;
        }

        $changes = Audit::diff($server);
        $server->save();

        if ($changes !== []) {
            Audit::log('server.updated', "Server {$server->name} bijgewerkt", $server, ['changes' => $changes]);
        }

        return redirect()
            ->route('servers.show', $server)
            ->with('success', __('servers.flash.saved'));
    }

    /**
     * Soft delete: history (metrics, alerts, incidents) is kept and the
     * server can be restored. All agent credentials are revoked.
     */
    public function destroy(Server $server, AgentCredentialService $credentials): RedirectResponse
    {
        Gate::authorize('admin');

        DB::transaction(function () use ($server, $credentials): void {
            $credentials->revokeAll($server);
            $server->delete();
        });

        Audit::log('server.deleted', "Server {$server->name} verwijderd (herstelbaar)", $server);

        return redirect()
            ->route('servers.index')
            ->with('success', __('servers.flash.deleted', ['name' => $server->name]));
    }

    public function restore(Server $server): RedirectResponse
    {
        Gate::authorize('admin');

        $server->restore();

        Audit::log('server.restored', "Server {$server->name} hersteld", $server);

        return redirect()
            ->route('servers.show', $server)
            ->with('success', __('servers.flash.restored'));
    }

    /**
     * Permanently delete a soft-deleted server and all its history.
     */
    public function forceDestroy(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('admin');

        abort_unless($server->trashed(), 404);

        $request->validate([
            'confirm_name' => ['required', 'string', Rule::in([$server->name])],
        ], [
            'confirm_name.in' => __('servers.flash.confirm_name'),
        ]);

        $name = $server->name;
        $id = $server->id;

        $server->forceDelete();

        Audit::log('server.force_deleted', "Server {$name} permanent verwijderd inclusief geschiedenis", null, ['server_id' => $id]);

        return redirect()
            ->route('servers.index', ['status' => 'deleted'])
            ->with('success', __('servers.flash.force_deleted', ['name' => $name]));
    }
}
