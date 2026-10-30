<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Server;
use App\Models\StatusPage;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StatusPageController extends Controller
{
    public function index(): View
    {
        Gate::authorize('admin');

        return view('status-pages.index', ['pages' => StatusPage::query()->withCount('components')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        Gate::authorize('admin');

        return $this->form(new StatusPage(['is_public' => true, 'show_uptime' => true, 'accent_color' => '#38bdf8']));
    }

    public function edit(StatusPage $statusPage): View
    {
        Gate::authorize('admin');

        return $this->form($statusPage->load('components'));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $page = DB::transaction(function () use ($request) {
            $page = StatusPage::query()->create([...$this->validated($request), 'created_by' => $request->user()->id]);
            $this->syncComponents($page, $request);

            return $page;
        });

        Audit::log('status_page.created', "Statuspagina '{$page->name}' aangemaakt", $page);

        return redirect()->route('status-pages.index')->with('success', __('status_pages.flash.created'));
    }

    public function update(Request $request, StatusPage $statusPage): RedirectResponse
    {
        Gate::authorize('admin');

        DB::transaction(function () use ($request, $statusPage): void {
            $statusPage->update($this->validated($request, $statusPage));
            $this->syncComponents($statusPage, $request);
        });

        Audit::log('status_page.updated', "Statuspagina '{$statusPage->name}' bijgewerkt", $statusPage);

        return redirect()->route('status-pages.index')->with('success', __('status_pages.flash.saved'));
    }

    public function destroy(StatusPage $statusPage): RedirectResponse
    {
        Gate::authorize('admin');

        $statusPage->delete();
        Audit::log('status_page.deleted', "Statuspagina '{$statusPage->name}' verwijderd");

        return redirect()->route('status-pages.index')->with('success', __('status_pages.flash.deleted'));
    }

    private function form(StatusPage $page): View
    {
        return view('status-pages.form', [
            'page' => $page,
            'servers' => Server::query()->orderBy('name')->get(['id', 'name']),
            'domains' => Domain::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function validated(Request $request, ?StatusPage $page = null): array
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('status_pages', 'slug')->ignore($page?->id), Rule::notIn(['create', 'admin', 'api'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'components' => ['array', 'max:50'],
            'components.*.subject' => ['required', 'regex:/^(server|domain):\d+$/'],
            'components.*.display_name' => ['required', 'string', 'max:100'],
            'components.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'accent_color' => strtolower($data['accent_color']),
            'is_public' => $request->boolean('is_public'),
            'show_incident_titles' => $request->boolean('show_incident_titles'),
            'show_uptime' => $request->boolean('show_uptime'),
        ];
    }

    private function syncComponents(StatusPage $page, Request $request): void
    {
        $page->components()->delete();

        foreach (array_values($request->input('components', [])) as $index => $component) {
            [$type, $id] = explode(':', $component['subject']);
            $exists = ($type === 'server' ? Server::query() : Domain::query())->whereKey((int) $id)->exists();

            if (! $exists) {
                throw ValidationException::withMessages(["components.{$index}.subject" => __('status_pages.flash.unknown_subject')]);
            }

            $page->components()->create([
                'display_name' => $component['display_name'],
                'description' => $component['description'] ?? null,
                'subject_type' => $type,
                'subject_id' => (int) $id,
                'sort_order' => $index,
            ]);
        }
    }
}
