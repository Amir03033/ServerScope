@props(['paginator'])

@if ($paginator->hasPages())
    <nav class="pagination" aria-label="{{ __('common.pagination.label') }}">
        <span>{{ __('common.pagination.results', ['from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'total' => $paginator->total()]) }}</span>
        <div class="pagination-links">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true">← {{ __('common.previous') }}</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev">← {{ __('common.previous') }}</a>
            @endif
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('common.next') }} →</a>
            @else
                <span aria-disabled="true">{{ __('common.next') }} →</span>
            @endif
        </div>
    </nav>
@endif
