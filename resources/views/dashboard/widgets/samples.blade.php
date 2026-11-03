@php
    use App\Support\Format;
    use App\Support\Time;
@endphp
{{-- Recent measurements (collapsed: details for whoever needs them) --}}
<details class="ss-panel ss-details">
    <summary>
        <span>
            <span class="ss-panel-title block">{{ __('dashboard.samples.title') }}</span>
            <span class="ss-panel-sub block">{{ $selectedServer ? __('dashboard.samples.text_for', ['name' => $selectedServer->name]) : __('dashboard.samples.text_none') }}</span>
        </span>
        <svg class="ico ss-chevron h-4 w-4"><use href="#i-chevron"/></svg>
    </summary>

    <div class="mt-4">
        @if ($recentSamples->isNotEmpty())
            <div class="ss-table-wrap" tabindex="0" role="region" aria-label="{{ __('dashboard.samples.table_label') }}">
                <table class="ss-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('dashboard.samples.time') }}</th>
                            <th scope="col" class="num">{{ __('dashboard.samples.cpu') }}</th>
                            <th scope="col" class="num">{{ __('dashboard.samples.ram') }}</th>
                            <th scope="col" class="num">{{ __('dashboard.samples.load_1') }}</th>
                            <th scope="col" class="num">{{ __('dashboard.samples.load_5') }}</th>
                            <th scope="col" class="num">{{ __('dashboard.samples.load_15') }}</th>
                            <th scope="col" class="num">{{ __('dashboard.samples.disk') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentSamples as $row)
                            @php
                                $rowTotal = (float) ($row->memory_total_bytes ?? 0);
                                $rowUsed = (float) ($row->memory_used_bytes ?? 0);
                                $rowMemoryPercent = $rowTotal > 0 ? ($rowUsed / $rowTotal) * 100 : null;

                                $firstDisk = collect($row->disks ?? [])->first();
                                $firstDiskTotal = (float) ($firstDisk['total_bytes'] ?? $firstDisk['total'] ?? 0);
                                $firstDiskUsed = (float) ($firstDisk['used_bytes'] ?? $firstDisk['used'] ?? 0);
                                $rowDiskPercent = $firstDiskTotal > 0 ? ($firstDiskUsed / $firstDiskTotal) * 100 : null;
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap">{{ $row->collected_at ? Time::dateTime($row->collected_at) : '—' }}</td>
                                <td class="num">{{ $row->cpu_percent !== null ? Format::percent($row->cpu_percent, 1) : '—' }}</td>
                                <td class="num">{{ $rowMemoryPercent !== null ? Format::percent($rowMemoryPercent, 1) : '—' }}</td>
                                <td class="num">{{ Format::number($row->load_1, 2) }}</td>
                                <td class="num">{{ Format::number($row->load_5, 2) }}</td>
                                <td class="num">{{ Format::number($row->load_15, 2) }}</td>
                                <td class="num">{{ $rowDiskPercent !== null ? Format::percent($rowDiskPercent, 1) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-empty-state icon="i-metrics" :title="__('dashboard.samples.none')" />
        @endif
    </div>
</details>
