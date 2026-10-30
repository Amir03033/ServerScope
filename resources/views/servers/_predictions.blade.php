@php
    use App\Models\Prediction;
    use App\Support\Format;
    use App\Support\Label;
    use App\Support\Time;
@endphp

<div class="ss-table-wrap">
    <table class="ss-table ss-table-cards">
        <thead>
            <tr>
                <th scope="col">{{ __('servers.predictions.col_finding') }}</th>
                <th scope="col">{{ __('servers.predictions.col_value') }}</th>
                <th scope="col">{{ __('servers.predictions.col_window') }}</th>
                <th scope="col">{{ __('servers.predictions.col_confidence') }}</th>
                <th scope="col">{{ __('servers.predictions.col_explanation') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($predictions as $prediction)
                <tr>
                    <td class="is-primary">
                        <x-severity :severity="$prediction->severity" />
                        <strong class="mt-1 block">{{ $prediction->kind === 'forecast' ? __('servers.predictions.forecast') : __('servers.predictions.anomaly') }}: {{ Label::of('prediction_metric', $prediction->metric, Prediction::METRIC_LABELS) }}{{ $prediction->dimension ? " ({$prediction->dimension})" : '' }}</strong>
                        <span class="block text-xs text-muted">{{ __('servers.predictions.since', ['date' => Time::dateTime($prediction->detected_at)]) }}</span>
                    </td>
                    <td data-label="{{ __('servers.predictions.col_value') }}" class="whitespace-nowrap text-sm">
                        @if ($prediction->kind === 'forecast')
                            {{ __('servers.predictions.over', ['percent' => Format::number((float) $prediction->threshold).'%', 'days' => $prediction->horizon_days]) }}
                            <span class="block text-xs text-muted">{{ $prediction->eta_low_at ? Time::date($prediction->eta_low_at) : '?' }} – {{ $prediction->eta_high_at ? Time::date($prediction->eta_high_at) : __('servers.predictions.undetermined') }}</span>
                        @else
                            {{ Format::number((float) $prediction->observed_value, 1) }} vs {{ Format::number((float) $prediction->baseline_value, 1) }}
                            <span class="block text-xs text-muted">z = {{ Format::number((float) $prediction->score, 1) }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('servers.predictions.col_window') }}" class="whitespace-nowrap text-sm">{{ Time::format($prediction->window_start, 'd-m') }} – {{ Time::format($prediction->window_end, 'd-m H:i') }}<span class="block text-xs text-muted">{{ __('servers.predictions.points', ['count' => $prediction->sample_count]) }}</span></td>
                    <td data-label="{{ __('servers.predictions.col_confidence') }}" class="text-sm">{{ $prediction->confidence ? Label::of('prediction_confidence', $prediction->confidence, Prediction::CONFIDENCE_LABELS) : '—' }}@if ($prediction->r_squared !== null)<span class="block text-xs text-muted">R² {{ Format::number($prediction->r_squared, 2) }}</span>@endif</td>
                    <td data-label="{{ __('servers.predictions.col_explanation') }}" class="text-sm">{{ $prediction->reason }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
