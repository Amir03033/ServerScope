{{-- Command palette (Ctrl/⌘ K): commands, search and a preview. Without
     JavaScript the triggers are plain links to /search. Commands only
     navigate or change the view (theme, focus); they never change data. --}}
@if (Route::has('search'))
@php
    // Navigation: every page in the menu this person can open (same filters as the menu).
    $paletteCommands = [];
    foreach ($navGroups ?? [] as $group => $items) {
        foreach ($items as $item) {
            foreach ([$item, ...($item['children'] ?? [])] as $entry) {
                if (Route::has($entry['route'])) {
                    $paletteCommands[] = ['id' => 'go:'.$entry['route'], 'group' => 'go', 'label' => __('palette.go', ['page' => __('nav.items.'.$entry['label'])]), 'keywords' => __('nav.items.'.$entry['label']).' '.$entry['label'], 'url' => route($entry['route']), 'icon' => $entry['icon']];
                }
            }
        }
    }

    // Quick filters: the existing lists with a filter already set.
    $paletteFilters = [
        ['servers.index', ['status' => 'offline'], 'servers_offline', 'i-server'],
        ['domains.index', ['status' => 'critical'], 'sites_down', 'i-globe'],
        ['domains.index', ['sort' => 'response'], 'sites_slow', 'i-globe'],
        ['incidents.index', [], 'incidents_open', 'i-siren'],
        ['incidents.index', ['state' => 'unacknowledged'], 'incidents_unacknowledged', 'i-siren'],
        ['incidents.index', ['state' => 'resolved'], 'incidents_resolved', 'i-siren'],
    ];
    foreach ($paletteFilters as [$route, $query, $key, $icon]) {
        if (Route::has($route)) {
            $paletteCommands[] = ['id' => 'filter:'.$key, 'group' => 'filter', 'label' => __('palette.filters.'.$key), 'keywords' => __('palette.keywords.'.$key), 'url' => route($route, $query), 'icon' => $icon];
        }
    }

    if (Route::has('servers.add') && auth()->user()?->can('admin')) {
        $paletteCommands[] = ['id' => 'do:add_server', 'group' => 'do', 'label' => __('palette.add_server'), 'keywords' => __('palette.keywords.add_server'), 'url' => route('servers.add'), 'icon' => 'i-plus'];
    }

    // View only: nothing is saved on the server.
    $paletteCommands[] = ['id' => 'view:theme', 'group' => 'view', 'label' => __('palette.theme'), 'keywords' => __('palette.keywords.theme'), 'action' => 'theme', 'icon' => 'i-monitor'];
    $paletteCommands[] = ['id' => 'view:focus', 'group' => 'view', 'label' => __('palette.focus'), 'keywords' => __('palette.keywords.focus'), 'action' => 'focus', 'icon' => 'i-target'];

    $paletteConfig = ['commands' => $paletteCommands, 'texts' => __('palette.js')];
@endphp
<dialog class="ss-dialog ss-palette" data-search-dialog aria-label="{{ __('nav.search.label') }}" data-search-url="{{ route('search') }}">
    <script type="application/json" id="palette-config">@json($paletteConfig)</script>
    <form method="GET" action="{{ route('search') }}" role="search" class="flex items-center gap-3 border-b border-line px-4">
        <svg class="ico h-[18px] w-[18px] text-muted"><use href="#i-search"/></svg>
        <label for="quick-search" class="sr-only">{{ __('nav.search.label') }}</label>
        <input id="quick-search" type="search" name="q" autocomplete="off" spellcheck="false"
               class="h-14 min-w-0 flex-1 border-0 bg-transparent text-[15px] text-fg outline-none placeholder:text-subtle"
               placeholder="{{ __('palette.placeholder') }}"
               role="combobox" aria-expanded="false" aria-controls="quick-search-results" aria-autocomplete="list">
        <kbd class="ss-kbd">Esc</kbd>
    </form>
    <div class="ss-palette-body">
        <div class="ss-palette-list" aria-live="polite">
            <ul id="quick-search-results" role="listbox" class="m-0 list-none p-0" data-search-results></ul>
            <p class="m-0 px-3 py-6 text-center text-sm text-muted" data-search-status hidden></p>
        </div>
        <aside class="ss-palette-preview" data-palette-preview hidden></aside>
    </div>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line px-4 py-2.5 text-xs text-muted">
        <span><kbd class="ss-kbd">↑</kbd> <kbd class="ss-kbd">↓</kbd> {{ __('nav.search.navigate') }}</span>
        <span><kbd class="ss-kbd">↵</kbd> {{ __('nav.search.select') }}</span>
        <span><kbd class="ss-kbd">Esc</kbd> {{ __('nav.search.close') }}</span>
        <span class="hidden sm:inline">{{ __('palette.tip') }}</span>
        @if (config('serverscope.docs_url'))
            <a class="ml-auto" href="{{ config('serverscope.docs_url') }}" target="_blank" rel="noopener">{{ __('search.types.docs') }}</a>
        @endif
    </div>
</dialog>
@endif
