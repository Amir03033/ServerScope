@php
    use App\Models\Domain;
    use App\Support\Label;
    use App\Support\Time;

@endphp

<x-layouts.app :title="__('search.title')">
    <x-slot:subtitle>{{ __('search.subtitle') }}</x-slot:subtitle>

    <section class="ss-panel">
        <form method="GET" action="{{ route('search') }}" class="mb-5 flex flex-wrap items-end gap-3" role="search">
            <div class="min-w-0 flex-1">
                <label class="ss-label" for="search-q">{{ __('search.term') }}</label>
                <input id="search-q" class="ss-input mt-1.5" type="search" name="q" value="{{ $q }}" autofocus placeholder="{{ __('search.placeholder') }}">
            </div>
            <button class="ss-btn ss-btn-primary" type="submit"><svg class="ico"><use href="#i-search"/></svg> {{ __('search.submit') }}</button>
        </form>

        @if (mb_strlen($q) < 2)
            <x-empty-state icon="i-search" :title="__('search.short_title')">{{ __('search.short_text') }}</x-empty-state>
        @elseif (collect($results)->every->isEmpty())
            <x-empty-state icon="i-search" :title="__('search.none_title')">{{ __('search.none_text', ['q' => $q]) }}</x-empty-state>
        @else
            <div class="grid gap-6 lg:grid-cols-3" data-highlight="{{ $q }}">
                <section aria-labelledby="results-servers">
                    <h2 id="results-servers" class="mt-0 mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ __('search.servers', ['count' => $results['servers']->count()]) }}</h2>
                    @foreach ($results['servers'] as $server)
                        <a class="ov-row" href="{{ route('servers.show', $server) }}"><x-status :status="$server->displayStatus()" /><span class="ov-main"><strong>{{ $server->name }}</strong><span class="ov-sub">{{ $server->hostname }}</span></span></a>
                    @endforeach
                </section>
                <section aria-labelledby="results-domains">
                    <h2 id="results-domains" class="mt-0 mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ __('search.domains', ['count' => $results['domains']->count()]) }}</h2>
                    @foreach ($results['domains'] as $domain)
                        <a class="ov-row" href="{{ route('domains.show', $domain) }}"><x-status :status="$domain->displayStatus()" :label="Label::of('domain_status', $domain->displayStatus(), Domain::STATUS_LABELS)" /><span class="ov-main"><strong>{{ $domain->displayName() }}</strong><span class="ov-sub">{{ $domain->url() }}</span></span></a>
                    @endforeach
                </section>
                <section aria-labelledby="results-incidents">
                    <h2 id="results-incidents" class="mt-0 mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ __('search.incidents', ['count' => $results['incidents']->count()]) }}</h2>
                    @foreach ($results['incidents'] as $incident)
                        <a class="ov-row" href="{{ route('incidents.show', $incident) }}"><x-status :status="$incident->status" /><span class="ov-main"><strong>#{{ $incident->id }} {{ $incident->title }}</strong><span class="ov-sub">{{ Time::dateTime($incident->opened_at) }}</span></span></a>
                    @endforeach
                </section>
                @if ($results['deployments']->isNotEmpty())
                    <section aria-labelledby="results-deployments">
                        <h2 id="results-deployments" class="mt-0 mb-2 text-xs font-semibold uppercase tracking-wide text-muted">{{ __('search.deployments', ['count' => $results['deployments']->count()]) }}</h2>
                        @foreach ($results['deployments'] as $deployment)
                            <a class="ov-row" href="{{ route('deployments.show', $deployment) }}"><x-status status="info" :label="$deployment->environment" /><span class="ov-main"><strong>{{ $deployment->label() }}</strong><span class="ov-sub">{{ Time::dateTime($deployment->deployed_at) }}</span></span></a>
                        @endforeach
                    </section>
                @endif
            </div>
        @endif
    </section>
</x-layouts.app>
