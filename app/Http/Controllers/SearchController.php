<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use App\Services\Domains\DomainNormalizer;
use App\Support\StatusLabel;
use App\Support\Time;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class SearchController extends Controller
{
    /**
     * The search page, or with "Accept: application/json" the same results
     * as JSON for the quick search (Ctrl/⌘ K). Both use search(), so the
     * queries and the team scoping are identical.
     */
    public function __invoke(Request $request, DomainNormalizer $normalizer): View|JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');
        $results = $this->search($q, $normalizer);

        if ($request->wantsJson()) {
            return response()->json(['q' => $q, 'results' => $this->toJson($results)]);
        }

        return view('search', ['q' => $q, 'results' => $results]);
    }

    /** @return array{servers: Collection, domains: Collection, incidents: Collection, deployments: Collection} */
    private function search(string $q, DomainNormalizer $normalizer): array
    {
        $results = ['servers' => collect(), 'domains' => collect(), 'incidents' => collect(), 'deployments' => collect()];

        if (mb_strlen($q) >= 2) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q)).'%';
            $ascii = $normalizer->normalize($q);

            $results['servers'] = Server::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('hostname', 'like', $like)->orWhere('ip_address', 'like', $like))
                ->orderBy('name')->limit(10)->get();

            $results['domains'] = Domain::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->when($ascii, fn ($w) => $w->orWhere('name', $ascii)))
                ->orderBy('name')->limit(10)->get();

            // Grouped so the team scope always applies to both conditions.
            $results['incidents'] = Incident::query()
                ->where(fn ($w) => $w->where('title', 'like', $like)
                    ->when(ctype_digit(ltrim($q, '#')), fn ($w) => $w->orWhere('id', (int) ltrim($q, '#'))))
                ->latest('opened_at')->limit(10)->get();

            $results['deployments'] = Deployment::query()
                ->where(fn ($w) => $w->where('application', 'like', $like)->orWhere('version', 'like', $like)
                    ->when(preg_match('/^[0-9a-f]{4,64}$/i', $q), fn ($w) => $w->orWhere('commit_sha', 'like', strtolower($q).'%')))
                ->latest('deployed_at')->limit(5)->get();
        }

        return $results;
    }

    /**
     * Only what the quick search shows: no internal fields.
     *
     * @return list<array{type: string, title: string, subtitle: string, status: string, status_label: string, url: string}>
     */
    private function toJson(array $results): array
    {
        $item = fn (string $type, string $title, ?string $subtitle, string $status, string $url) => [
            'type' => $type,
            'title' => $title,
            'subtitle' => (string) $subtitle,
            'status' => $status,
            'status_label' => StatusLabel::for($status),
            'url' => $url,
        ];

        return [
            ...$results['servers']->map(fn (Server $server) => $item('server', $server->name, $server->hostname, $server->displayStatus(), route('servers.show', $server)))->all(),
            ...$results['domains']->map(fn (Domain $domain) => $item('domain', $domain->displayName(), $domain->url(), $domain->displayStatus(), route('domains.show', $domain)))->all(),
            ...$results['incidents']->map(fn (Incident $incident) => $item('incident', '#'.$incident->id.' '.$incident->title, Time::dateTime($incident->opened_at), $incident->status, route('incidents.show', $incident)))->all(),
            ...$results['deployments']->map(fn (Deployment $d) => $item('deployment', $d->label(), $d->environment.' · '.Time::dateTime($d->deployed_at), 'info', route('deployments.show', $d)))->all(),
        ];
    }
}
