<?php

namespace App\Http\Controllers;

use App\Http\Requests\PageRequest;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\MonitoredPage;
use App\Models\PageChange;
use App\Monitoring\Net\BlockedTargetException;
use App\Monitoring\Net\TargetGuard;
use App\Services\Websites\DiscoveryScope;
use App\Services\Websites\PageRegistrar;
use App\Services\Websites\WebsiteOverview;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Website overview and management of the monitored pages of a domain.
 * Everyone can view; administrators add, change and remove pages.
 */
class WebsitePageController extends Controller
{
    public const BULK_ACTIONS = ['approve', 'reject', 'pause', 'resume', 'check', 'delete', 'interval'];

    public function index(Request $request, Domain $domain, WebsiteOverview $overview): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['up', 'degraded', 'down', 'unknown', 'paused', 'pending', 'rejected', 'stale'])],
            'source' => ['nullable', Rule::in(array_keys(MonitoredPage::SOURCES))],
            'sort' => ['nullable', Rule::in(['url', 'status', 'response', 'checked', 'failure', 'changed'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $status = $filters['status'] ?? null;
        $query = MonitoredPage::query()->where('domain_id', $domain->id);

        match ($status) {
            null => $query->where('approval_status', '!=', 'rejected'),
            'pending', 'rejected' => $query->where('approval_status', $status),
            'paused' => $query->approved()->where('is_active', false),
            'stale' => $query->whereIn('id', $overview->stalePages($domain)->pluck('id')),
            default => $query->monitored()->where('status', $status),
        };

        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($filters['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(url) LIKE ?', [$term])->orWhereRaw('LOWER(title) LIKE ?', [$term]));
        }

        $query->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source));

        $direction = $filters['direction'] ?? null;

        match ($filters['sort'] ?? 'status') {
            'url' => $query->orderBy('url', $direction ?? 'asc'),
            'response' => $query->orderByRaw('last_response_ms IS NULL')->orderBy('last_response_ms', $direction ?? 'desc'),
            'checked' => $query->orderByRaw('last_checked_at IS NULL')->orderBy('last_checked_at', $direction ?? 'desc'),
            'failure' => $query->orderByRaw('last_failure_at IS NULL')->orderBy('last_failure_at', $direction ?? 'desc'),
            'changed' => $query->orderByRaw('content_changed_at IS NULL')->orderBy('content_changed_at', $direction ?? 'desc'),
            default => $query->orderByRaw("CASE approval_status WHEN 'pending' THEN 1 ELSE 0 END")
                ->orderByRaw("CASE status WHEN 'down' THEN 0 WHEN 'degraded' THEN 1 WHEN 'unknown' THEN 2 ELSE 3 END")
                ->orderBy('url'),
        };

        $now = now();

        return view('websites.pages', [
            'domain' => $domain,
            'pages' => $query->paginate(50)->withQueryString()->through(fn (MonitoredPage $page) => $page->setRelation('domain', $domain)),
            'filters' => $filters,
            'status' => $status,
            'counts' => $overview->counts($domain),
            'stats24h' => $overview->responseStats($domain, $now->copy()->subDay(), $now),
            'stats7d' => $overview->responseStats($domain, $now->copy()->subDays(7), $now),
            'daily' => $overview->daily($domain, 14),
            'incidents' => $overview->activeIncidents($domain),
            'changes' => $overview->recentChanges($domain, 10),
            'latestRun' => $domain->discoveryRuns()->latest('id')->first(),
        ]);
    }

    public function create(Domain $domain): View
    {
        Gate::authorize('admin');

        return view('websites.page-form', ['domain' => $domain, 'page' => new MonitoredPage(['domain_id' => $domain->id])]);
    }

    public function store(PageRequest $request, Domain $domain, PageRegistrar $registrar): RedirectResponse
    {
        $result = $registrar->add($domain, $request->normalizedUrls, $request->pageData());
        $added = count($result['added']);

        Audit::log('website.pages_added', "{$added} pagina('s) toegevoegd aan {$domain->name}", $domain, ['urls' => array_slice($request->normalizedUrls, 0, 50)]);

        if ($result['limit_reached']) {
            $limit = \App\Support\PlanLimits::pagesPerWebsite(\App\Models\Team::query()->find($domain->team_id));

            return redirect()->route('websites.pages', $domain)->with('error', __('websites.flash.limit', ['limit' => $limit, 'added' => $added]));
        }

        $message = $added === 1 ? __('websites.flash.added_one') : __('websites.flash.added_many', ['count' => $added]);

        return redirect()->route('websites.pages', $domain)->with('success', $message.($result['duplicates'] ? __('websites.flash.duplicates', ['count' => $result['duplicates']]) : ''));
    }

    public function show(Request $request, MonitoredPage $page, WebsiteOverview $overview): View
    {
        $page->load('domain');
        $type = $request->validate(['result' => ['nullable', Rule::in(['up', 'degraded', 'down'])]])['result'] ?? null;
        $now = now();

        return view('websites.page', [
            'page' => $page,
            'domain' => $page->domain,
            'checks' => $page->checks()->when($type, fn ($q) => $q->where('result', $type))->latest('checked_at')->latest('id')->paginate(25, ['*'], 'checks_page')->withQueryString(),
            'result' => $type,
            'changes' => $page->changes()->with('acknowledger:id,name')->latest('detected_at')->limit(20)->get(),
            'stats24h' => $overview->responseStats($page->domain, $now->copy()->subDay(), $now, $page->id),
            'stats30d' => $overview->responseStats($page->domain, $now->copy()->subDays(30), $now, $page->id),
            'daily' => $overview->daily($page->domain, 30, $page->id),
            'series' => $page->checks()->where('checked_at', '>=', $now->copy()->subDay())->orderBy('checked_at')->limit(1500)->get(['checked_at', 'response_time_ms', 'success']),
            'incidents' => $page->incidents()->latest('opened_at')->limit(10)->get(),
            'groupIncidents' => Incident::query()->whereHas('impacts', fn ($q) => $q->where('resource_type', 'page')->where('resource_id', $page->id))->latest('opened_at')->limit(10)->get(),
        ]);
    }

    public function edit(MonitoredPage $page): View
    {
        Gate::authorize('admin');

        return view('websites.page-form', ['domain' => $page->domain, 'page' => $page]);
    }

    public function update(PageRequest $request, MonitoredPage $page): RedirectResponse
    {
        $url = $request->normalizedUrls[0];
        $page->fill($request->pageData());

        if ($url !== $page->url) {
            // Results and content baseline belong to the old URL.
            $page->forceFill([
                'url' => $url, 'url_hash' => MonitoredPage::hashUrl($url), 'status' => 'unknown', 'consecutive_failures' => 0,
                'consecutive_successes' => 0, 'content_fingerprint' => null, 'content_snapshot' => null, 'header_snapshot' => null, 'failing_since' => null,
            ]);
        }

        if (! $page->content_monitoring) {
            $page->forceFill(['content_fingerprint' => null, 'content_snapshot' => null]);
        }

        $changes = Audit::diff($page);
        unset($changes['content_snapshot'], $changes['header_snapshot']);
        $page->save();

        if ($changes !== []) {
            Audit::log('website.page_updated', "Pagina {$page->url} bijgewerkt", $page, ['changes' => $changes]);
        }

        return redirect()->route('pages.show', $page)->with('success', __('websites.flash.saved'));
    }

    public function destroy(MonitoredPage $page): RedirectResponse
    {
        Gate::authorize('admin');

        $domain = $page->domain;
        $this->deletePage($page);
        Audit::log('website.page_deleted', "Pagina {$page->url} verwijderd inclusief controlegeschiedenis", $domain);

        return redirect()->route('websites.pages', $domain)->with('success', __('websites.flash.deleted'));
    }

    public function pause(MonitoredPage $page): RedirectResponse
    {
        Gate::authorize('admin');

        $page->forceFill(['is_active' => false, 'paused_at' => now()])->save();
        Audit::log('website.page_paused', "Monitoring gepauzeerd voor {$page->url}", $page);

        return back()->with('success', __('websites.flash.paused'));
    }

    public function resume(MonitoredPage $page): RedirectResponse
    {
        Gate::authorize('admin');

        $page->forceFill(['is_active' => true, 'paused_at' => null])->save();
        $this->queueCheck($page);
        Audit::log('website.page_resumed', "Monitoring hervat voor {$page->url}", $page);

        return back()->with('success', __('websites.flash.resumed'));
    }

    public function check(MonitoredPage $page): RedirectResponse
    {
        Gate::authorize('admin');

        if (! $page->isMonitored()) {
            return back()->with('error', __('websites.flash.not_checkable'));
        }

        $key = 'page-check-now:'.$page->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return back()->with('error', __('websites.flash.wait', ['seconds' => RateLimiter::availableIn($key)]));
        }

        RateLimiter::hit($key, 60);
        $this->queueCheck($page);

        return back()->with('success', __('websites.flash.check_queued'));
    }

    public function bulk(Request $request, Domain $domain, TargetGuard $guard): RedirectResponse
    {
        Gate::authorize('admin');

        $validated = $request->validate([
            'action' => ['required', Rule::in(self::BULK_ACTIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'interval' => ['required_if:action,interval', 'nullable', 'integer', 'between:'.\App\Support\PlanLimits::minIntervalMinutes().',1440'],
        ], ['ids.required' => __('websites.validation.select_first')]);

        // Only pages of this website, whatever ids were posted.
        $pages = MonitoredPage::query()->where('domain_id', $domain->id)->whereIn('id', $validated['ids'])->get();
        $done = 0;
        $skipped = [];

        if ($validated['action'] === 'check') {
            $key = 'website-bulk-check:'.$domain->id;

            if (RateLimiter::tooManyAttempts($key, 1)) {
                return back()->with('error', __('websites.flash.wait', ['seconds' => RateLimiter::availableIn($key)]));
            }

            RateLimiter::hit($key, 60);
        }

        $scope = new DiscoveryScope($domain, [...$domain->discoverySettings(), 'allowed_patterns' => [], 'excluded_patterns' => [], 'include_subdomains' => true]);

        foreach ($pages as $page) {
            switch ($validated['action']) {
                case 'approve':
                    if ($page->approval_status === 'approved') {
                        break;
                    }

                    // Discovered URLs are validated again before monitoring starts.
                    try {
                        if (! $scope->hostAllowed($page->host())) {
                            throw new BlockedTargetException(__('websites.validation.outside_domain'), 'scope');
                        }

                        $guard->resolve($page->url);
                    } catch (BlockedTargetException $exception) {
                        $skipped[] = "{$page->url}: {$exception->getMessage()}";
                        break;
                    }

                    $page->forceFill(['approval_status' => 'approved', 'is_active' => true])->save();
                    $this->queueCheck($page);
                    $done++;
                    break;

                case 'reject':
                    $page->forceFill(['approval_status' => 'rejected'])->save();
                    $done++;
                    break;

                case 'pause':
                    $page->forceFill(['is_active' => false, 'paused_at' => now()])->save();
                    $done++;
                    break;

                case 'resume':
                    $page->forceFill(['is_active' => true, 'paused_at' => null])->save();
                    $this->queueCheck($page);
                    $done++;
                    break;

                case 'check':
                    if ($page->isMonitored()) {
                        $this->queueCheck($page);
                        $done++;
                    }
                    break;

                case 'interval':
                    $page->forceFill(['check_interval_minutes' => (int) $validated['interval']])->save();
                    $done++;
                    break;

                case 'delete':
                    $this->deletePage($page);
                    $done++;
                    break;
            }
        }

        Audit::log('website.bulk_'.$validated['action'], "Bulkactie {$validated['action']} op {$done} pagina('s) van {$domain->name}", $domain, ['ids' => $pages->pluck('id')->all()]);

        $response = back()->with('success', __('websites.flash.bulk_done', ['count' => $done, 'action' => __('websites.flash.bulk_actions.'.$validated['action'])]));

        return $skipped !== [] ? $response->with('error', __('websites.flash.not_approved', ['list' => implode('; ', array_slice($skipped, 0, 5))])) : $response;
    }

    public function acknowledgeChange(Request $request, PageChange $change): RedirectResponse
    {
        Gate::authorize('admin');

        if ($change->acknowledged_at === null) {
            $change->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id])->save();
            Audit::log('website.change_acknowledged', "Wijziging van {$change->page?->url} bevestigd als verwacht", $change->page);
        }

        return back()->with('success', __('websites.flash.acknowledged'));
    }

    private function queueCheck(MonitoredPage $page): void
    {
        PageRegistrar::queueCheck($page);
    }

    private function deletePage(MonitoredPage $page): void
    {
        PageRegistrar::delete($page);
    }
}
