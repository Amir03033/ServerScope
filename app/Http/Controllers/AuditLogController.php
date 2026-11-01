<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Rules\TeamMember;
use App\Support\Time;
use App\Tenancy\Tenant;
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
            'type' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z_\\\\]+$/'],
            'resource' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = isset($filters['from']) ? Time::parse($filters['from'].' 00:00') : null;
        $to = isset($filters['to']) ? Time::parse($filters['to'].' 23:59:59') : null;

        $logs = AuditLog::query()
            ->with('user:id,name,email')
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', str_replace('_', '\_', $v).'%'))
            ->when($filters['user'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('description', 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $v).'%'))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['resource'] ?? null, fn ($q, $v) => $q->where('auditable_id', $v))
            // Dates are entered in the display timezone (stored UTC).
            ->when($from, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->latest('id')
            ->paginate(40)
            ->withQueryString();

        return view('audit-logs.index', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => TeamMember::options(Tenant::id()),
            'types' => AuditLog::query()->whereNotNull('auditable_type')->distinct()->orderBy('auditable_type')->pluck('auditable_type'),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action')
                ->map(fn ($a) => explode('.', $a)[0])->unique()->values(),
        ]);
    }
}
