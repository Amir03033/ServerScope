<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\Websites\DiscoveryLauncher;
use App\Services\Websites\UrlNormalizer;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Discovery settings and runs for a website.
 */
class WebsiteDiscoveryController extends Controller
{
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
            'default_interval' => ['required', 'integer', 'between:'.\App\Support\PlanLimits::minIntervalMinutes().',1440'],
            'broad_confirmed' => ['boolean'],
        ], [], [
            'max_pages' => 'maximum aantal pagina\'s',
            'max_depth' => 'maximale diepte',
            'default_interval' => 'standaardinterval',
            'sitemap_urls.*' => 'sitemap-URL',
        ]);

        foreach ($validated['sitemap_urls'] as $url) {
            if (UrlNormalizer::normalize($url, keepQuery: true) === null) {
                throw ValidationException::withMessages(['sitemap_urls' => __('websites.validation.invalid_sitemap', ['url' => $url])]);
            }
        }

        // Broader coverage than the safe default must be confirmed explicitly.
        $broad = $validated['max_pages'] > (int) config('serverscope.websites.discovery_default_max_pages', 50)
            || $validated['max_depth'] > 2 || $validated['include_subdomains'] || $validated['auto_monitor'];

        if ($broad && ! $validated['broad_confirmed']) {
            throw ValidationException::withMessages(['broad_confirmed' => __('websites.validation.broad_confirm')]);
        }

        unset($validated['broad_confirmed']);
        $old = $domain->discoverySettings();
        $domain->forceFill(['discovery_settings' => $validated])->save();
        Audit::log('website.discovery_settings', "Ontdekkingsinstellingen van {$domain->name} bijgewerkt", $domain, ['old' => $old, 'new' => $validated]);

        return back()->with('success', __('websites.flash.discovery_saved'));
    }

    public function run(Request $request, Domain $domain, DiscoveryLauncher $launcher): RedirectResponse
    {
        Gate::authorize('admin');

        $result = $launcher->start($domain, $request->user());

        if ($result['error'] !== null) {
            return back()->with('error', $result['error']);
        }

        return redirect()->route('websites.discovery', $domain)->with('success', __('websites.flash.discovery_started'));
    }
}
