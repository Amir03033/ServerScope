<?php

namespace App\Http\Controllers;

use App\Jobs\RunPageDiscovery;
use App\Models\Domain;
use App\Models\PageDiscoveryRun;
use App\Services\Websites\UrlNormalizer;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Discovery settings and runs for a website.
 */
class WebsiteDiscoveryController extends Controller
{
    /** A run that has not finished after this many minutes is considered dead. */
    private const STALE_AFTER_MINUTES = 15;

    public function show(Domain $domain): View
    {
        return view('websites.discovery', [
            'domain' => $domain,
            'settings' => $domain->discoverySettings(),
            'runs' => $domain->discoveryRuns()->with('triggeredBy:id,name')->latest('id')->paginate(10),
            'pendingCount' => $domain->pages()->where('approval_status', 'pending')->count(),
        ]);
    }

    public function update(Request $request, Domain $domain): RedirectResponse
    {
        Gate::authorize('admin');

        $limit = (int) config('serverscope.websites.discovery_max_pages_limit', 1000);
        $lines = fn ($value) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value) ?: []), fn ($l) => $l !== ''));

        $request->merge([
            'allowed_patterns' => $lines($request->input('allowed_patterns')),
            'excluded_patterns' => $lines($request->input('excluded_patterns')),
            'sitemap_urls' => $lines($request->input('sitemap_urls')),
            'keep_query' => $request->boolean('keep_query'),
            'include_subdomains' => $request->boolean('include_subdomains'),
            'auto_monitor' => $request->boolean('auto_monitor'),
            'broad_confirmed' => $request->boolean('broad_confirmed'),
        ]);

        $validated = $request->validate([
            'max_pages' => ['required', 'integer', "between:1,{$limit}"],
            'max_depth' => ['required', 'integer', 'between:0,5'],
            'allowed_patterns' => ['array', 'max:50'],
            'allowed_patterns.*' => ['string', 'max:255'],
            'excluded_patterns' => ['array', 'max:50'],
            'excluded_patterns.*' => ['string', 'max:255'],
            'sitemap_urls' => ['array', 'max:20'],
            'sitemap_urls.*' => ['string', 'max:2048'],
            'keep_query' => ['boolean'],
            'include_subdomains' => ['boolean'],
            'auto_monitor' => ['boolean'],
            'default_interval' => ['required', 'integer', 'between:'.(int) config('serverscope.monitoring.min_interval_minutes', 1).',1440'],
            'broad_confirmed' => ['boolean'],
        ], [], [
            'max_pages' => 'maximum aantal pagina\'s',
            'max_depth' => 'maximale diepte',
            'default_interval' => 'standaardinterval',
            'sitemap_urls.*' => 'sitemap-URL',
        ]);

        foreach ($validated['sitemap_urls'] as $url) {
            if (UrlNormalizer::normalize($url, keepQuery: true) === null) {
                throw ValidationException::withMessages(['sitemap_urls' => "Ongeldige sitemap-URL: {$url}"]);
            }
        }

        // Broader coverage than the safe default must be confirmed explicitly.
        $broad = $validated['max_pages'] > (int) config('serverscope.websites.discovery_default_max_pages', 50)
            || $validated['max_depth'] > 2 || $validated['include_subdomains'] || $validated['auto_monitor'];

        if ($broad && ! $validated['broad_confirmed']) {
            throw ValidationException::withMessages(['broad_confirmed' => 'Bevestig dat je een bredere ontdekking wilt (meer pagina\'s, grotere diepte, subdomeinen of automatisch monitoren).']);
        }

        unset($validated['broad_confirmed']);
        $old = $domain->discoverySettings();
        $domain->forceFill(['discovery_settings' => $validated])->save();
        Audit::log('website.discovery_settings', "Ontdekkingsinstellingen van {$domain->name} bijgewerkt", $domain, ['old' => $old, 'new' => $validated]);

        return back()->with('success', 'Ontdekkingsinstellingen opgeslagen.');
    }

    public function run(Request $request, Domain $domain): RedirectResponse
    {
        Gate::authorize('admin');

        if (! $domain->is_active || $domain->trashed()) {
            return back()->with('error', 'Hervat de monitoring van dit domein voordat je pagina\'s ontdekt.');
        }

        // Runs that never finished (killed worker) are closed first.
        $domain->discoveryRuns()->whereIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update(['status' => 'failed', 'errors' => json_encode(['Niet afgerond binnen '.self::STALE_AFTER_MINUTES.' minuten.']), 'finished_at' => now()]);

        if ($domain->discoveryRuns()->whereIn('status', ['queued', 'running'])->exists()) {
            return back()->with('error', 'Er loopt al een ontdekking voor deze website.');
        }

        $key = 'website-discovery:'.$domain->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return back()->with('error', 'Te veel ontdekkingen; probeer het over '.ceil(RateLimiter::availableIn($key) / 60).' minuten opnieuw.');
        }

        RateLimiter::hit($key, 3600);

        $run = PageDiscoveryRun::query()->create([
            'domain_id' => $domain->id,
            'triggered_by' => $request->user()->id,
            'status' => 'queued',
            'settings' => $domain->discoverySettings(),
        ]);

        Audit::log('website.discovery_started', "Paginaontdekking gestart voor {$domain->name}", $domain);
        RunPageDiscovery::dispatch($run->id, $domain->id);

        return redirect()->route('websites.discovery', $domain)->with('success', 'Ontdekking gestart. Gevonden pagina\'s verschijnen onder "Wacht op goedkeuring".');
    }
}
