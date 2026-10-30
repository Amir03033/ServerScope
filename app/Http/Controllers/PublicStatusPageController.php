<?php

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Services\Reports\StatusPagePresenter;
use App\Support\Locale;
use App\Tenancy\TeamScope;
use App\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PublicStatusPageController extends Controller
{
    public function show(Request $request, string $slug, StatusPagePresenter $presenter): View|Response
    {
        $page = $this->resolve($request, $slug);

        if ($page instanceof Response) {
            return $page;
        }

        return view('status-pages.public', ['status' => $this->data($page, $presenter)]);
    }

    public function json(Request $request, string $slug, StatusPagePresenter $presenter): JsonResponse|Response
    {
        $page = $this->resolve($request, $slug);

        if ($page instanceof Response) {
            return $page;
        }

        return response()->json($this->data($page, $presenter))->header('Cache-Control', 'public, max-age=30');
    }

    private function resolve(Request $request, string $slug): StatusPage|Response
    {
        // Public route without a tenant: slugs are unique platform-wide.
        $page = StatusPage::query()->withoutGlobalScope(TeamScope::class)->where('slug', $slug)->first();

        abort_if($page === null, 404);

        // Private pages are only visible to signed-in members of the page's
        // team; for everyone else they do not exist.
        if (! $page->is_public && $request->user() === null) {
            return redirect()->guest(route('login'));
        }

        abort_if(! $page->is_public && ! $request->user()->belongsToTeam($page->team_id), 404);

        return $page;
    }

    private function data(StatusPage $page, StatusPagePresenter $presenter): array
    {
        // Rendered as the page's own team, so only that team's resources
        // and incidents can appear.
        return Cache::remember(Locale::cacheKey("status-page:{$page->id}:{$page->updated_at?->timestamp}"), 30, fn () => Tenant::run($page->team_id, fn () => $presenter->present($page)));
    }
}
