<?php

namespace App\Http\Controllers;

use App\Models\EnrollmentToken;
use App\Services\EnrollmentService;
use App\Support\AgentInstall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * "Server toevoegen": one-command installation for Linux with one-time
 * enrollment tokens, next to the existing manual registration.
 */
class EnrollmentTokenController extends Controller
{
    public function add(): View
    {
        $isAdmin = Gate::allows('admin');

        return view('servers.add', [
            'isAdmin' => $isAdmin,
            'urlProblem' => AgentInstall::urlProblem(),
            'command' => AgentInstall::command(),
            'scriptUrl' => route('agent.install-script'),
            'pending' => $isAdmin ? EnrollmentToken::query()->pending()->with('creator:id,name')->latest('id')->get() : collect(),
            'recent' => $isAdmin ? EnrollmentToken::query()->whereNotNull('used_at')->with('server:id,name,last_seen_at')->latest('used_at')->limit(5)->get() : collect(),
            // Shown once, right after creating.
            'newToken' => session('enrollment_token'),
            'newTokenId' => session('enrollment_token_id'),
            'ttl' => (int) config('serverscope.enrollment.ttl_minutes', 60),
        ]);
    }

    public function store(Request $request, EnrollmentService $enrollment): RedirectResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate(['label' => ['nullable', 'string', 'max:100']]);

        if ($problem = AgentInstall::urlProblem()) {
            return redirect()->route('servers.add')->with('error', $problem);
        }

        $max = max(1, (int) config('serverscope.enrollment.max_pending', 10));

        if ($enrollment->pendingCount() >= $max) {
            return redirect()->route('servers.add')->with('error', __('servers.add.flash.too_many', ['max' => $max]));
        }

        [$token, $plain] = $enrollment->create($request->user(), $validated['label'] ?? null);

        return redirect()->route('servers.add')
            ->with('enrollment_token', $plain)
            ->with('enrollment_token_id', $token->id);
    }

    public function destroy(Request $request, EnrollmentToken $enrollmentToken, EnrollmentService $enrollment): RedirectResponse
    {
        Gate::authorize('admin');

        $enrollment->revoke($enrollmentToken, $request->user());

        return redirect()->route('servers.add')->with('success', __('servers.add.flash.revoked', ['prefix' => $enrollmentToken->prefix]));
    }

    /** Live status for the "Server toevoegen" page. */
    public function status(EnrollmentToken $enrollmentToken): JsonResponse
    {
        Gate::authorize('admin');

        $enrollmentToken->loadMissing('server');
        $server = $enrollmentToken->server;

        return response()->json([
            'state' => $enrollmentToken->state(),
            'server_name' => $server?->name,
            'server_url' => $server ? route('servers.show', $server) : null,
            'expires_at' => $enrollmentToken->expires_at->toIso8601String(),
        ]);
    }
}
