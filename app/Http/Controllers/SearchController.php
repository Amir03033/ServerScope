<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Incident;
use App\Models\Server;
use App\Services\Domains\DomainNormalizer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __invoke(Request $request, DomainNormalizer $normalizer): View
    {
        $q = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');
        $results = ['servers' => collect(), 'domains' => collect(), 'incidents' => collect()];

        if (mb_strlen($q) >= 2) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q)).'%';
            $ascii = $normalizer->normalize($q);

            $results['servers'] = Server::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('hostname', 'like', $like)->orWhere('ip_address', 'like', $like))
                ->orderBy('name')->limit(10)->get();

            $results['domains'] = Domain::query()
                ->where(fn ($w) => $w->where('name', 'like', $like)->when($ascii, fn ($w) => $w->orWhere('name', $ascii)))
                ->orderBy('name')->limit(10)->get();

            $results['incidents'] = Incident::query()
                ->where('title', 'like', $like)
                ->when(ctype_digit(ltrim($q, '#')), fn ($w) => $w->orWhere('id', (int) ltrim($q, '#')))
                ->latest('opened_at')->limit(10)->get();
        }

        return view('search', ['q' => $q, 'results' => $results]);
    }
}
