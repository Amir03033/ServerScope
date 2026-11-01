{{-- Quick search (Ctrl/⌘ K). Without JavaScript the triggers are plain links to /search. --}}
@if (Route::has('search'))
<dialog class="ss-dialog" data-search-dialog aria-label="{{ __('nav.search.label') }}" data-search-url="{{ route('search') }}">
    <form method="GET" action="{{ route('search') }}" role="search" class="flex items-center gap-3 border-b border-line px-4">
        <svg class="ico h-[18px] w-[18px] text-muted"><use href="#i-search"/></svg>
        <label for="quick-search" class="sr-only">{{ __('nav.search.label') }}</label>
        <input id="quick-search" type="search" name="q" autocomplete="off" spellcheck="false"
               class="h-14 min-w-0 flex-1 border-0 bg-transparent text-[15px] text-fg outline-none placeholder:text-subtle"
               placeholder="{{ __('nav.search.placeholder') }}"
               role="combobox" aria-expanded="false" aria-controls="quick-search-results" aria-autocomplete="list">
        <kbd class="ss-kbd">Esc</kbd>
    </form>
    <div class="max-h-[22rem] overflow-y-auto p-2" aria-live="polite">
        <ul id="quick-search-results" role="listbox" class="m-0 list-none p-0" data-search-results></ul>
        <p class="m-0 px-3 py-6 text-center text-sm text-muted" data-search-status hidden></p>
    </div>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-line px-4 py-2.5 text-xs text-muted">
        <span><kbd class="ss-kbd">↑</kbd> <kbd class="ss-kbd">↓</kbd> {{ __('nav.search.navigate') }}</span>
        <span><kbd class="ss-kbd">↵</kbd> {{ __('nav.search.select') }}</span>
        <span><kbd class="ss-kbd">Esc</kbd> {{ __('nav.search.close') }}</span>
        @if (config('serverscope.docs_url'))
            <a class="ml-auto" href="{{ config('serverscope.docs_url') }}" target="_blank" rel="noopener">{{ __('search.types.docs') }}</a>
        @endif
    </div>
</dialog>
@endif
