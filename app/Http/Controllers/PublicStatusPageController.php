<?php

namespace App\Http\Controllers;

use App\Models\StatusPage;
use App\Services\Reports\StatusPagePresenter;
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
        $page = StatusPage::query()->where('slug', $slug)->first();

        abort_if($page === null, 404);

        // Private pages are only visible to signed-in users.
        if (! $page->is_public && $request->user() === null) {
            return redirect()->guest(route('login'));
        }

        return $page;
    }

    private function data(StatusPage $page, StatusPagePresenter $presenter): array
    {
        return Cache::remember("status-page:{$page->id}:{$page->updated_at?->timestamp}", 30, fn () => $presenter->present($page));
    }
}
