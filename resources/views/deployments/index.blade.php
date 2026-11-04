@php use App\Support\Time; @endphp

<x-layouts.app :title="__('deployments.title')">
    <x-slot:breadcrumb><span>›</span> {{ __('deployments.title') }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ __('deployments.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('deployments.subtitle') }}</x-slot:subtitle>
    <x-slot:actions>
        <a href="{{ route('deploy-sources.index') }}" class="ss-btn ss-btn-secondary"><svg class="ico"><use href="#i-refresh"/></svg> {{ __('deployments.sources_button') }}</a>
    </x-slot:actions>

    <section class="ss-panel">
        <form method="GET" action="{{ route('deployments.index') }}" data-filters-label="{{ __('common.filters') }}" class="ss-filters mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_auto_auto_auto_auto] lg:items-end" role="search">
            <div>
                <label class="ss-label" for="application">{{ __('deployments.application') }}</label>
                <select id="application" class="ss-input mt-1.5" name="application"><option value="">{{ __('deployments.all') }}</option>
                    @foreach ($applications as $application)<option value="{{ $application }}" @selected(($filters['application'] ?? null) === $application)>{{ $application }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="ss-label" for="environment">{{ __('deployments.environment') }}</label>
                <input id="environment" class="ss-input mt-1.5" type="search" name="environment" value="{{ $filters['environment'] ?? '' }}" placeholder="production">
            </div>
            <div><button class="ss-btn ss-btn-secondary" type="submit">{{ __('deployments.filter') }}</button></div>
        </form>

        @if ($deployments->isEmpty())
            <x-empty-state icon="i-flag" :title="__('deployments.empty_title')">
                {{ __('deployments.empty_text') }}
                <x-slot:actions><a class="ss-btn ss-btn-primary" href="{{ route('deploy-sources.index') }}">{{ __('deployments.sources_button') }}</a></x-slot:actions>
            </x-empty-state>
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead><tr><th scope="col">{{ __('deployments.col_deployment') }}</th><th scope="col">{{ __('deployments.environment') }}</th><th scope="col">{{ __('deployments.col_commit') }}</th><th scope="col">{{ __('deployments.col_resources') }}</th><th scope="col">{{ __('deployments.col_when') }}</th></tr></thead>
                    <tbody>
                        @foreach ($deployments as $deployment)
                            <tr>
                                <td class="is-primary">
                                    <a class="ss-hit font-semibold text-fg no-underline hover:text-accent [overflow-wrap:anywhere]" href="{{ route('deployments.show', $deployment) }}">{{ $deployment->application }}</a>
                                    @if ($deployment->title)<span class="block text-sm text-fg [overflow-wrap:anywhere]">{{ $deployment->title }}</span>@endif
                                    <span class="block text-xs text-muted">#{{ $deployment->id }}@if ($deployment->version) · {{ $deployment->version }}@endif @if ($deployment->actor) · {{ $deployment->actor }}@endif @if ($deployment->source) · {{ Lang::has('deployments.sources.'.$deployment->source) ? __('deployments.sources.'.$deployment->source) : $deployment->source }}@endif</span>
                                </td>
                                <td data-label="{{ __('deployments.environment') }}">{{ $deployment->environment }}</td>
                                <td data-label="{{ __('deployments.col_commit') }}" class="font-mono text-xs">{{ $deployment->commit_sha ? substr($deployment->commit_sha, 0, 12) : '—' }}</td>
                                <td data-label="{{ __('deployments.col_resources') }}">{{ count($deployment->resourceRefs()) }}</td>
                                <td data-label="{{ __('deployments.col_when') }}" class="whitespace-nowrap">{{ Time::dateTime($deployment->deployed_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-pagination :paginator="$deployments" />
        @endif
    </section>
</x-layouts.app>
