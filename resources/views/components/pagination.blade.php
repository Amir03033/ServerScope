@props(['paginator'])

@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Paginering">
        <span>Resultaten {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} van {{ $paginator->total() }}</span>
        <div class="pagination-links">
            @if ($paginator->onFirstPage())
                <span aria-disabled="true">← Vorige</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev">← Vorige</a>
            @endif
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next">Volgende →</a>
            @else
                <span aria-disabled="true">Volgende →</span>
            @endif
        </div>
    </nav>
@endif
