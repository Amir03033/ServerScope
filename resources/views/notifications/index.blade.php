@php use App\Support\Time; @endphp

<x-layouts.app :title="__('notifications.title')">
    <x-slot:subtitle>{{ __('notifications.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        @if ($tab === 'inbox')
            <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="ss-btn ss-btn-secondary" type="submit"><svg class="ico"><use href="#i-check"/></svg> {{ __('notifications.mark_all') }}</button></form>
        @endif
        <a class="ss-btn ss-btn-ghost" href="{{ route('profile.edit') }}">{{ __('notifications.preferences') }}</a>
    </x-slot:actions>

    <section class="ss-panel">
        <nav class="ss-tabs-scroll mb-4" aria-label="{{ __('notifications.tabs_label') }}">
            <div class="ss-segmented">
                <a href="{{ route('notifications.index') }}" @if ($tab === 'inbox') aria-current="page" @endif>{{ __('notifications.inbox') }} @if ($sidebarUnreadNotifications ?? 0)<span class="ss-count">{{ $sidebarUnreadNotifications }}</span>@endif</a>
                <a href="{{ route('notifications.index', ['tab' => 'deliveries']) }}" @if ($tab === 'deliveries') aria-current="page" @endif>{{ __('notifications.deliveries') }}{{ auth()->user()->can('admin') ? __('notifications.all_users') : '' }}</a>
            </div>
        </nav>

        @if ($tab === 'inbox')
            @if ($notifications->isEmpty())
                <x-empty-state icon="i-message" :title="__('notifications.empty_title')">
                    {{ auth()->user()->notify_in_app ? __('notifications.empty_on') : __('notifications.empty_off') }}
                </x-empty-state>
            @else
                <div class="grid">
                    @foreach ($notifications as $notification)
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}" class="m-0">
                            @csrf
                            <button type="submit" class="ss-row w-full cursor-pointer border-0 bg-transparent text-left">
                                <x-status :status="$notification->data['severity'] ?? 'info'" />
                                <span class="ss-row-main">
                                    <strong @class(['text-muted' => $notification->read_at])>{{ $notification->data['message'] ?? $notification->data['title'] ?? __('notifications.fallback') }}</strong>
                                    <span class="ss-row-sub">{{ Time::dateTime($notification->created_at) }}@if ($notification->read_at){{ __('notifications.read') }}@else · <span class="font-semibold text-accent">{{ __('notifications.new') }}</span>@endif</span>
                                </span>
                            </button>
                        </form>
                    @endforeach
                </div>
                <x-pagination :paginator="$notifications" />
            @endif
        @else
            @if ($deliveries->isEmpty())
                <x-empty-state icon="i-history" :title="__('notifications.deliveries_empty_title')">{{ __('notifications.deliveries_empty_text') }}</x-empty-state>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table ss-table-cards">
                        <thead><tr><th scope="col">{{ __('notifications.col_time') }}</th><th scope="col">{{ __('notifications.col_recipient') }}</th><th scope="col">{{ __('notifications.col_channel') }}</th><th scope="col">{{ __('notifications.col_subject') }}</th><th scope="col">{{ __('notifications.col_status') }}</th></tr></thead>
                        <tbody>
                            @foreach ($deliveries as $log)
                                <tr>
                                    <td class="is-primary whitespace-nowrap">{{ Time::dateTimeSeconds($log->created_at) }}</td>
                                    <td data-label="{{ __('notifications.col_recipient') }}" class="text-sm">{{ $log->user?->name ?? $log->recipient ?? '—' }}</td>
                                    <td data-label="{{ __('notifications.col_channel') }}"><span class="ss-chip">{{ $log->channel === '-' ? '—' : (Lang::has('notifications.channel.'.$log->channel) ? __('notifications.channel.'.$log->channel) : $log->channel) }}</span></td>
                                    <td data-label="{{ __('notifications.col_subject') }}" class="text-sm [overflow-wrap:anywhere]">
                                        <span>
                                            {{ $log->subject ?? $log->kind }}
                                            @if ($log->incident)<span class="block text-xs"><a class="text-accent" href="{{ route('incidents.show', $log->incident_id) }}">{{ __('notifications.incident', ['id' => $log->incident_id]) }}</a></span>@endif
                                        </span>
                                    </td>
                                    <td data-label="{{ __('notifications.col_status') }}">
                                        <span>
                                            <x-status :status="$log->status === 'suppressed' ? 'paused' : $log->status" :label="Lang::has('notifications.status.'.$log->status) ? __('notifications.status.'.$log->status) : $log->status" />
                                            @if ($log->error)<span class="block text-xs text-muted">{{ $log->error }}</span>@endif
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-pagination :paginator="$deliveries" />
            @endif
        @endif
    </section>
</x-layouts.app>
