{{-- Whether a resource met its availability target. --}}
<span>
    @if ($row['meets_target'] === null)
        <span class="text-xs text-muted">{{ __('reports.no_data') }}</span>
    @else
        <x-status :status="$row['meets_target'] ? 'healthy' : 'critical'" :label="$row['meets_target'] ? __('reports.met') : __('reports.not_met')" />
    @endif
    <span class="block text-xs text-muted">{{ \App\Support\Format::percent($row['target'], 2) }}</span>
</span>
