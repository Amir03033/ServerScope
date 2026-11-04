@php
    use App\Support\Time;

    $isAdmin = auth()->user()->can('admin');
    $tone = ['up' => 'up', 'down' => 'down', 'paused' => 'paused', 'new' => 'unknown'];
@endphp

<x-layouts.app :title="__('heartbeats.title')">
    <x-slot:pageTitle>{{ __('heartbeats.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('heartbeats.subtitle') }}</x-slot:subtitle>
    @if ($isAdmin && $heartbeats->isNotEmpty())
        <x-slot:actions>
            <a class="ss-btn ss-btn-primary" href="{{ route('heartbeats.create') }}"><svg class="ico"><use href="#i-plus"/></svg> {{ __('heartbeats.new') }}</a>
        </x-slot:actions>
    @endif

    @if ($heartbeats->isEmpty())
        <section class="ss-panel ss-hb-empty" aria-labelledby="hb-empty-title">
            <span class="ss-empty-icon"><svg class="ico"><use href="#i-clock"/></svg></span>
            <h2 id="hb-empty-title" class="m-0 text-lg font-semibold">{{ __('heartbeats.empty.title') }}</h2>
            <p class="m-0 max-w-2xl text-sm text-muted">{{ __('heartbeats.empty.text') }}</p>
            <ol class="ss-hb-steps">
                @foreach (['step1', 'step2', 'step3'] as $step)
                    <li>{{ __('heartbeats.empty.'.$step) }}</li>
                @endforeach
            </ol>
            @if ($isAdmin)
                <a class="ss-btn ss-btn-primary" href="{{ route('heartbeats.create') }}"><svg class="ico"><use href="#i-plus"/></svg> {{ __('heartbeats.new') }}</a>
            @endif
        </section>
    @else
        <div class="grid gap-4">
            <dl class="ss-hb-summary" aria-label="{{ __('heartbeats.title') }}">
                @foreach (['down', 'up', 'new', 'paused'] as $state)
                    <div data-state="{{ $state }}" @class(['is-zero' => ($counts[$state] ?? 0) === 0])>
                        <dt>{{ __('heartbeats.counts.'.$state) }}</dt>
                        <dd>{{ $counts[$state] ?? 0 }}</dd>
                    </div>
                @endforeach
            </dl>

            <section class="ss-panel ss-hb-list" aria-labelledby="hb-list-title">
                <h2 id="hb-list-title" class="sr-only">{{ __('heartbeats.title') }}</h2>
                <div class="ss-hb-head" aria-hidden="true">
                    <span>{{ __('heartbeats.columns.job') }}</span><span>{{ __('heartbeats.columns.runs') }}</span><span>{{ __('heartbeats.columns.last') }}</span><span>{{ __('heartbeats.columns.next') }}</span>
                </div>
                <ul class="ss-hb-rows" role="list">
                    @foreach ($heartbeats as $heartbeat)
                        @php $runs = $recent[$heartbeat->id] ?? collect(); $lateAt = $heartbeat->lateAt(); @endphp
                        <li>
                            <a class="ss-hb-row" href="{{ route('heartbeats.show', $heartbeat) }}" data-state="{{ $heartbeat->status }}">
                                <span class="ss-hb-main">
                                    <span class="ss-hb-name">{{ $heartbeat->name }}</span>
                                    <span class="ss-hb-meta">
                                        <x-status :status="$tone[$heartbeat->status]" :label="__('heartbeats.status.'.$heartbeat->status)" />
                                        <span>{{ $heartbeat->scheduleLabel() }}</span>
                                        @if ($heartbeat->server)<span class="ss-hb-server"><svg class="ico" aria-hidden="true"><use href="#i-server"/></svg>{{ $heartbeat->server->name }}</span>@endif
                                    </span>
                                </span>
                                <span class="ss-hb-runs" aria-label="{{ __('heartbeats.columns.runs') }}" title="{{ __('heartbeats.runs_legend') }}">
                                    @for ($i = 0; $i < 20 - $runs->count(); $i++)<i></i>@endfor
                                    @foreach ($runs as $run)<i data-kind="{{ $run->kind }}" title="{{ __('heartbeats.show.kind.'.$run->kind) }} · {{ Time::dateTime($run->received_at) }}"></i>@endforeach
                                </span>
                                <span class="ss-hb-cell" data-label="{{ __('heartbeats.columns.last') }}">{{ $heartbeat->last_ping_at ? Time::relative($heartbeat->last_ping_at) : __('heartbeats.never') }}</span>
                                <span @class(['ss-hb-cell', 'is-late' => $heartbeat->status === 'down' && $lateAt]) data-label="{{ __('heartbeats.columns.next') }}">
                                    @if ($heartbeat->status === 'down' && $lateAt)
                                        {{ __('heartbeats.overdue', ['time' => Time::relative($heartbeat->next_due_at)]) }}
                                    @elseif ($lateAt)
                                        {{ Time::relative($lateAt) }}
                                    @else
                                        —
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    @endif
</x-layouts.app>
