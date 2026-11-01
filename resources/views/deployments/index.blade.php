@php use App\Support\Time; @endphp

<x-layouts.app :title="__('deployments.title')">
    <x-slot:breadcrumb><span>›</span> {{ __('deployments.title') }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ __('deployments.title') }}</x-slot:pageTitle>
    <x-slot:subtitle>{{ __('deployments.subtitle') }}</x-slot:subtitle>

    <section class="ss-panel">
        <form method="GET" action="{{ route('deployments.index') }}" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_auto_auto_auto_auto] lg:items-end" role="search">
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
            <x-empty-state icon="i-flag" :title="__('deployments.empty_title')">{{ __('deployments.empty_text') }}</x-empty-state>
        @else
            <div class="ss-table-wrap">
                <table class="ss-table ss-table-cards">
                    <thead><tr><th scope="col">{{ __('deployments.col_deployment') }}</th><th scope="col">{{ __('deployments.environment') }}</th><th scope="col">{{ __('deployments.col_commit') }}</th><th scope="col">{{ __('deployments.col_resources') }}</th><th scope="col">{{ __('deployments.col_when') }}</th></tr></thead>
                    <tbody>
                        @foreach ($deployments as $deployment)
                            <tr>
                                <td class="is-primary">
                                    <a class="font-semibold text-fg no-underline hover:text-accent [overflow-wrap:anywhere]" href="{{ route('deployments.show', $deployment) }}">{{ $deployment->application }}</a>
                                    <span class="block text-xs text-muted">#{{ $deployment->id }}@if ($deployment->version) · {{ $deployment->version }}@endif @if ($deployment->actor) · {{ $deployment->actor }}@endif</span>
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
