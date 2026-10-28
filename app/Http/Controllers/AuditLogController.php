<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('admin');

        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:50', 'regex:/^[a-z_.]+$/'],
            'user' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', str_replace('_', '\_', $v).'%'))
            ->when($filters['user'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('description', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $v).'%'))
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return view('audit-logs.index', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => \App\Rules\TeamMember::options(\App\Tenancy\Tenant::id()),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')
                ->map(fn ($a) => explode('.', $a)[0])->unique()->values(),
        ]);
    }
}
