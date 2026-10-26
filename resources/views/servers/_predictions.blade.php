@php
    use App\Models\Prediction;
    use App\Support\Time;
@endphp

<div class="table-wrap">
    <table class="data-table">
        <thead><tr><th>Bevinding</th><th>Waarde</th><th>Venster</th><th>Zekerheid</th><th>Toelichting</th></tr></thead>
        <tbody>
            @foreach ($predictions as $prediction)
                <tr>
                    <td>
                        <x-severity :severity="$prediction->severity" />
                        <strong style="display:block;margin-top:4px">{{ $prediction->kind === 'forecast' ? 'Prognose' : 'Afwijking' }}: {{ $prediction->metricLabel() }}</strong>
                        <span class="cell-sub">sinds {{ Time::format($prediction->detected_at) }}</span>
                    </td>
                    <td class="nowrap small">
                        @if ($prediction->kind === 'forecast')
                            {{ number_format((float) $prediction->threshold, 0) }}% over ~{{ $prediction->horizon_days }} d
                            <span class="cell-sub">{{ $prediction->eta_low_at ? Time::format($prediction->eta_low_at, 'd-m-Y') : '?' }} – {{ $prediction->eta_high_at ? Time::format($prediction->eta_high_at, 'd-m-Y') : 'onbepaald' }}</span>
                        @else
                            {{ number_format((float) $prediction->observed_value, 1, ',', '.') }} vs {{ number_format((float) $prediction->baseline_value, 1, ',', '.') }}
                            <span class="cell-sub">z = {{ number_format((float) $prediction->score, 1, ',', '.') }}</span>
                        @endif
                    </td>
                    <td class="nowrap small">{{ Time::format($prediction->window_start, 'd-m') }} – {{ Time::format($prediction->window_end, 'd-m H:i') }}<span class="cell-sub">{{ $prediction->sample_count }} punten</span></td>
                    <td class="small">{{ Prediction::CONFIDENCE_LABELS[$prediction->confidence] ?? '—' }}@if ($prediction->r_squared !== null)<span class="cell-sub">R² {{ number_format($prediction->r_squared, 2, ',', '.') }}</span>@endif</td>
                    <td class="small">{{ $prediction->reason }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
