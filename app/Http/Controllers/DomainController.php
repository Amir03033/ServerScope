<?php

namespace App\Http\Controllers;

use App\Http\Requests\DomainRequest;
use App\Jobs\RunDomainCheck;
use App\Models\Domain;
use App\Services\Domains\DnsMonitor;
use App\Services\Domains\DomainCheckRunner;
use App\Services\Domains\DomainNormalizer;
use App\Services\Reports\UptimeCalculator;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DomainController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['healthy', 'warning', 'critical', 'unknown', 'paused', 'deleted'])],
            'sort' => ['nullable', Rule::in(['name', 'status', 'ssl', 'expiry', 'response'])],
        ]);

        $status = $filters['status'] ?? null;

        if ($status === 'deleted') {
            Gate::authorize('admin');
        }

        $query = Domain::query();

        match ($status) {
            'deleted' => $query->onlyTrashed(),
            'paused' => $query->where('is_active', false),
            null => null,
            default => $query->where('is_active', true)->where('status', $status),
        };

        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($filters['q'])).'%';
            $ascii = app(DomainNormalizer::class)->normalize($filters['q']);
            $query->where(fn ($q) => $q->where('name', 'like', $term)->when($ascii, fn ($q) => $q->orWhere('name', 'like', '%'.$ascii.'%')));
        }

        match ($filters['sort'] ?? 'status') {
            'name' => $query->orderBy('name'),
            'ssl' => $query->orderByRaw('ssl_valid_to IS NULL')->orderBy('ssl_valid_to'),
            'expiry' => $query->orderByRaw('registration_expires_at IS NULL')->orderBy('registration_expires_at'),
            'response' => $query->orderByRaw('http_last_response_ms IS NULL')->orderByDesc('http_last_response_ms'),
            default => $query->orderByRaw("CASE status WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 WHEN 'unknown' THEN 2 ELSE 3 END")->orderBy('name'),
        };

        $domains = $query->paginate(25)->withQueryString();

        $counts = [
            'all' => Domain::query()->count(),
            'healthy' => Domain::query()->where('is_active', true)->where('status', 'healthy')->count(),
            'warning' => Domain::query()->where('is_active', true)->where('status', 'warning')->count(),
            'critical' => Domain::query()->where('is_active', true)->where('status', 'critical')->count(),
            'unknown' => Domain::query()->where('is_active', true)->where('status', 'unknown')->count(),
            'paused' => Domain::query()->where('is_active', false)->count(),
            'deleted' => Domain::onlyTrashed()->count(),
        ];

        return view('domains.index', compact('domains', 'filters', 'counts', 'status'));
    }

    public function create(): View
    {
        Gate::authorize('admin');

        return view('domains.create', ['domain' => new Domain]);
    }

    public function store(DomainRequest $request): RedirectResponse
    {
        $domain = Domain::query()->create([...$request->domainData(), 'created_by' => $request->user()->id]);

        Audit::log('domain.created', "Domein {$domain->name} toegevoegd", $domain);

        $this->dispatchAll($domain);

        return redirect()
            ->route('domains.show', $domain)
            ->with('success', __('domains.flash.created'));
    }

    public function show(Request $request, Domain $domain, UptimeCalculator $uptime): View
    {
        $type = $request->validate(['type' => ['nullable', Rule::in(DomainCheckRunner::TYPES)]])['type'] ?? null;

        $now = now();
        $stats = [
            '24h' => $uptime->domain($domain, $now->copy()->subDay(), $now),
            '7d' => $uptime->domain($domain, $now->copy()->subDays(7), $now),
            '30d' => $uptime->domain($domain, $now->copy()->subDays(30), $now),
        ];

        $checks = $domain->checks()
            ->when($type, fn ($q) => $q->where('type', $type))
            ->latest('checked_at')
            ->latest('id')
            ->paginate(20, ['*'], 'checks_page')
            ->withQueryString();

        $dnsRecords = [];

        foreach (DnsMonitor::RECORD_TYPES as $recordType) {
            $snapshot = $domain->dnsSnapshots()->where('record_type', $recordType)->latest('last_seen_at')->latest('id')->first();
            $dnsRecords[$recordType] = $snapshot;
        }

        return view('domains.show', [
            'domain' => $domain,
            'stats' => $stats,
            'daily' => $uptime->dailyBuckets($domain, 30),
            'series' => $uptime->responseSeries($domain, $now->copy()->subDay(), $now),
            'checks' => $checks,
            'type' => $type,
            'dnsRecords' => $dnsRecords,
            'dnsChanges' => $domain->dnsChanges()->latest('detected_at')->limit(15)->get(),
            'incidents' => $domain->incidents()->latest('opened_at')->limit(10)->get(),
            'certificates' => $domain->sslCertificates()->latest('first_seen_at')->limit(10)->get(),
            'predictions' => \App\Models\Prediction::query()->forResource($domain)->current()->get(),
            'latestSsl' => $domain->checks()->where('type', 'ssl')->whereNotNull('details')->latest('checked_at')->first(),
        ]);
    }

    public function edit(Domain $domain): View
    {
        Gate::authorize('admin');

        return view('domains.edit', compact('domain'));
    }

    public function update(DomainRequest $request, Domain $domain): RedirectResponse
    {
        $domain->fill($request->domainData());

        $nameChanged = $domain->isDirty('name') || $domain->isDirty('scheme') || $domain->isDirty('path');
        $changes = Audit::diff($domain);

        // Results for the old hostname no longer apply.
        if ($domain->isDirty('name')) {
            $domain->forceFill([
                'status' => 'unknown', 'http_status' => 'unknown', 'http_consecutive_failures' => 0, 'http_consecutive_successes' => 0,
                'ssl_status' => 'unknown', 'ssl_valid_from' => null, 'ssl_valid_to' => null, 'ssl_issuer' => null, 'ssl_subject' => null,
                'ssl_fingerprint' => null, 'ssl_warning_threshold' => null, 'dns_status' => 'unknown', 'dns_consecutive_failures' => 0,
                'expiry_status' => 'unknown', 'registration_expires_at' => null, 'registered_at' => null, 'registrar' => null,
                'registrable_domain' => null, 'expiry_source' => null, 'expiry_warning_threshold' => null, 'expiry_next_check_at' => null,
            ]);
            $domain->dnsSnapshots()->delete();
        }
        $domain->save();

        if ($changes !== []) {
            Audit::log('domain.updated', "Domein {$domain->name} bijgewerkt", $domain, ['changes' => $changes]);
        }

        if ($nameChanged) {
            $this->dispatchAll($domain);
        }

        return redirect()->route('domains.show', $domain)->with('success', __('domains.flash.saved'));
    }

    public function pause(Domain $domain): RedirectResponse
    {
        Gate::authorize('admin');

        $domain->forceFill(['is_active' => false, 'paused_at' => now()])->save();
        Audit::log('domain.paused', "Monitoring gepauzeerd voor {$domain->name}", $domain);

        return back()->with('success', __('domains.flash.paused'));
    }

    public function resume(Domain $domain): RedirectResponse
    {
        Gate::authorize('admin');

        $domain->forceFill(['is_active' => true, 'paused_at' => null])->save();
        Audit::log('domain.resumed', "Monitoring hervat voor {$domain->name}", $domain);
        $this->dispatchAll($domain);

        return back()->with('success', __('domains.flash.resumed'));
    }

    public function checkNow(Domain $domain): RedirectResponse
    {
        Gate::authorize('admin');

        $key = 'domain-check-now:'.$domain->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return back()->with('error', __('domains.flash.wait', ['seconds' => RateLimiter::availableIn($key)]));
        }

        RateLimiter::hit($key, 60);
        $this->dispatchAll($domain, includeExpiry: false);

        return back()->with('success', __('domains.flash.checks_queued'));
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        Gate::authorize('admin');

        $domain->delete();
        Audit::log('domain.deleted', "Domein {$domain->name} verwijderd (herstelbaar)", $domain);

        return redirect()->route('domains.index')->with('success', __('domains.flash.deleted', ['name' => $domain->name]));
    }

    public function restore(Domain $domain): RedirectResponse
    {
        Gate::authorize('admin');

        $domain->restore();
        Audit::log('domain.restored', "Domein {$domain->name} hersteld", $domain);
        $this->dispatchAll($domain);

        return redirect()->route('domains.show', $domain)->with('success', __('domains.flash.restored'));
    }

    public function forceDestroy(Request $request, Domain $domain): RedirectResponse
    {
        Gate::authorize('admin');
        abort_unless($domain->trashed(), 404);

        $request->validate(['confirm_name' => ['required', Rule::in([$domain->name, $domain->displayName()])]], [
            'confirm_name.in' => __('domains.validation.confirm_name'),
        ]);

        $name = $domain->name;
        $domain->incidents()->update(['subject_id' => null, 'subject_type' => null]);
        $domain->forceDelete();
        Audit::log('domain.force_deleted', "Domein {$name} permanent verwijderd inclusief controlegeschiedenis");

        return redirect()->route('domains.index', ['status' => 'deleted'])->with('success', __('domains.flash.force_deleted', ['name' => $name]));
    }

    private function dispatchAll(Domain $domain, bool $includeExpiry = true): void
    {
        if (! $domain->is_active) {
            return;
        }

        foreach (DomainCheckRunner::TYPES as $type) {
            if (($type !== 'expiry' || $includeExpiry) && DomainCheckRunner::applies($domain, $type)) {
                $domain->forceFill(["{$type}_next_check_at" => now()->addMinutes(DomainCheckRunner::interval($domain, $type))])->saveQuietly();
                RunDomainCheck::dispatch($domain->id, $type);
            }
        }
    }
}
