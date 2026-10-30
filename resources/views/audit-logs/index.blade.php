@php
    use App\Support\Time;
    use Illuminate\Support\Str;
@endphp

<x-layouts.app :title="__('admin.audit.title')">
    <x-slot:subtitle>{{ __('admin.audit.subtitle') }}</x-slot:subtitle>

    <section class="ss-panel">
        <form method="GET" action="{{ route('audit-logs.index') }}" class="mb-4 grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto] sm:items-end" role="search">
            <div>
                <label class="ss-label" for="q">{{ __('admin.audit.search') }}</label>
                <input id="q" class="ss-input mt-1.5" type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ __('admin.audit.search_placeholder') }}">
            </div>
            <div class="grid grid-cols-2 gap-3 sm:contents">
                <div>
                    <label class="ss-label" for="action">{{ __('admin.audit.action') }}</label>
                    <select id="action" class="ss-input mt-1.5" name="action"><option value="">{{ __('admin.audit.all') }}</option>
                        @foreach ($actions as $action)<option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>{{ $action }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="ss-label" for="user">{{ __('admin.audit.user') }}</label>
                    <select id="user" class="ss-input mt-1.5" name="user"><option value="">{{ __('admin.audit.everyone') }}</option>
                        @foreach ($users as $u)<option value="{{ $u->id }}" @selected((int) ($filters['user'] ?? 0) === $u->id)>{{ $u->name }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div><button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-search"/></svg> {{ __('admin.audit.filter') }}</button></div>
        </form>

        @if ($logs->isEmpty())
            <x-empty-state icon="i-history" :title="__('admin.audit.empty_title')">{{ __('admin.audit.empty_text') }}</x-empty-state>
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead><tr><th scope="col">{{ __('admin.audit.col_time') }}</th><th scope="col">{{ __('admin.audit.col_user') }}</th><th scope="col">{{ __('admin.audit.col_action') }}</th><th scope="col">{{ __('admin.audit.col_description') }}</th><th scope="col">{{ __('admin.audit.col_ip') }}</th></tr></thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr>
                                <td class="is-primary whitespace-nowrap">{{ Time::dateTimeSeconds($log->created_at) }}</td>
                                <td data-label="{{ __('admin.audit.col_user') }}" class="text-sm">{{ $log->user?->name ?? __('admin.audit.system') }}</td>
                                <td data-label="{{ __('admin.audit.col_action') }}"><span class="ss-chip font-mono">{{ $log->action }}</span></td>
                                <td data-label="{{ __('admin.audit.col_description') }}" class="text-sm [overflow-wrap:anywhere]">
                                    <span>
                                        {{ $log->description }}
                                        @if (! empty($log->properties['changes']))
                                            <details class="mt-1"><summary class="cursor-pointer text-muted">{{ __('admin.audit.changes', ['count' => count($log->properties['changes'])]) }}</summary>
                                                <div class="ss-diff">
                                                    @foreach ($log->properties['changes'] as $field => $change)
                                                        <div>{{ $field }}: {{ Str::limit(json_encode($change['old'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 60) }} → {{ Str::limit(json_encode($change['new'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 60) }}</div>
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endif
                                    </span>
                                </td>
                                <td data-label="{{ __('admin.audit.col_ip') }}" class="font-mono text-xs">{{ $log->ip_address ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$logs" />
        @endif
    </section>
</x-layouts.app>
