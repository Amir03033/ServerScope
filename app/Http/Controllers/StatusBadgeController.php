<?php

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Services\Reports\StatusBadge;
use App\Services\Reports\StatusPagePresenter;
use App\Tenancy\TeamScope;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Live SVG badges of a public status page: the overall state, or one
 * component's. A badge shows exactly what the public page shows (the
 * component name and its state), so only public pages have badges; for
 * private or unknown pages and components it does not exist.
 *
 * Options: ?theme=light|dark, ?lang=en|nl, ?label= (own text, max 40).
 */
class StatusBadgeController extends Controller
{
    public function show(Request $request, string $slug, StatusPagePresenter $presenter, StatusBadge $badge, ?int $component = null): Response
    {
        $page = StatusPage::query()->withoutGlobalScope(TeamScope::class)->where('slug', $slug)->first();

        abort_if($page === null || ! $page->is_public, 404);

        $lang = $request->query('lang');

        if (is_string($lang) && in_array($lang, ['en', 'nl'], true)) {
            app()->setLocale($lang);
        }

        $data = $presenter->cached($page);

        if ($component === null) {
            $state = $data['overall'];
            $label = $data['name'];
        } else {
            $row = collect($data['components'])->firstWhere('id', $component);
            abort_if($row === null, 404);
            $state = $row['state'];
            $label = $row['name'];
        }

        $custom = $request->query('label');

        if (is_string($custom) && trim($custom) !== '') {
            $label = $custom;
        }

        // Control characters out, and short enough to stay a badge.
        $label = mb_strimwidth(trim((string) preg_replace('/[\p{C}]+/u', ' ', (string) $label)), 0, 40, '…');
        $theme = $request->query('theme') === 'dark' ? 'dark' : 'light';

        $svg = $badge->render($label === '' ? 'status' : $label, $state, __('status_page.state.'.$state), $theme);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            // Short: badges are live. Proxies such as GitHub's image cache honour this.
            'Cache-Control' => 'public, max-age=60, s-maxage=60',
            // An SVG opened on its own runs nothing and loads nothing.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
