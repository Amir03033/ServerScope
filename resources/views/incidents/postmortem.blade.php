@php
    use App\Support\Time;

    $editing = request()->boolean('edit') || $errors->any();
    $isAdmin = auth()->user()->can('admin');
@endphp

<x-layouts.app :title="__('postmortems.page_title', ['title' => $incident->title])">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('incidents.index') }}">{{ __('incidents.title') }}</a> <span>›</span> <a href="{{ route('incidents.show', $incident) }}">#{{ $incident->id }}</a> <span>›</span> {{ __('postmortems.title') }}</x-slot:breadcrumb>
    <x-slot:pageTitle>{{ $incident->title }}</x-slot:pageTitle>
    <x-slot:subtitle>
        @if ($pm['updated'])
            {{ __('postmortems.edited', ['name' => $pm['editor'] ?? __('incidents.system'), 'time' => Time::relative($pm['updated'])]) }}
        @else
            {{ __('postmortems.draft') }}
        @endif
    </x-slot:subtitle>
    <x-slot:actions>
        <div class="ss-noprint flex flex-wrap gap-2">
            <a href="{{ route('incidents.show', $incident) }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('postmortems.actions.back') }}</a>
            @unless ($editing)
                <a href="{{ route('incidents.postmortem', [$incident, 'edit' => 1]) }}" class="ss-btn ss-btn-secondary"><svg class="ico"><use href="#i-edit"/></svg> {{ __('postmortems.actions.edit') }}</a>
                <button type="button" class="ss-btn ss-btn-primary" popovertarget="pm-share"><svg class="ico"><use href="#i-link"/></svg> {{ __('postmortems.actions.share') }}@if ($postmortem?->isShared())<span class="ss-filters-count" aria-label="{{ __('postmortems.share.shared_badge') }}">✓</span>@endif</button>
            @endunless
        </div>
    </x-slot:actions>

    <div class="ss-doc-shell">
        @if ($editing)
            <form method="POST" action="{{ route('incidents.postmortem.update', $incident) }}" data-postmortem-form>
                @csrf
                @method('PUT')
                @include('incidents._postmortem-document', ['editing' => true])
                <div class="ss-doc-savebar">
                    <a class="ss-btn ss-btn-secondary" href="{{ route('incidents.postmortem', $incident) }}">{{ __('postmortems.actions.cancel') }}</a>
                    <button type="submit" class="ss-btn ss-btn-primary">{{ __('postmortems.actions.save') }}</button>
                </div>
            </form>
        @else
            @include('incidents._postmortem-document')
            <div class="ss-doc-tools ss-noprint">
                <a class="ss-btn ss-btn-ghost ss-btn-sm" href="{{ route('incidents.postmortem.markdown', $incident) }}"><svg class="ico"><use href="#i-download"/></svg> {{ __('postmortems.actions.markdown') }}</a>
                <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-print><svg class="ico"><use href="#i-file"/></svg> {{ __('postmortems.actions.print') }}</button>
            </div>
        @endif
    </div>

    {{-- Share: a public, read-only link (team administrators). --}}
    <div id="pm-share" popover class="ss-sheet" role="dialog" aria-labelledby="pm-share-title">
        <div class="grid gap-4 p-5">
            <div class="flex items-start gap-3">
                <span class="ss-sheet-icon"><svg class="ico h-[18px] w-[18px]"><use href="#i-link"/></svg></span>
                <div class="min-w-0">
                    <h2 id="pm-share-title" class="m-0 text-base font-semibold text-fg">{{ __('postmortems.share.title') }}</h2>
                    <p class="m-0 mt-1 text-sm text-muted">{{ $postmortem?->isShared() ? __('postmortems.share.text') : __('postmortems.share.off_text') }}</p>
                </div>
            </div>

            @if ($postmortem?->isShared())
                <div>
                    <label class="ss-label mb-1.5" for="pm-share-link">{{ __('postmortems.share.link') }}</label>
                    <div class="flex gap-2">
                        <input id="pm-share-link" class="ss-input min-w-0 flex-1 font-mono !text-xs" readonly value="{{ $postmortem->publicUrl() }}">
                        <button class="ss-btn ss-btn-secondary" type="button" data-copy-from="pm-share-link">{{ __('common.copy') }}</button>
                    </div>
                </div>
            @endif

            @if ($isAdmin)
                <div class="flex flex-wrap justify-end gap-2">
                    @if ($postmortem?->isShared())
                        <form method="POST" action="{{ route('incidents.postmortem.unshare', $incident) }}" data-confirm="{{ __('postmortems.share.stop_confirm') }}">@csrf @method('DELETE')<button type="submit" class="ss-btn ss-btn-secondary">{{ __('postmortems.share.stop') }}</button></form>
                        <a class="ss-btn ss-btn-primary" href="{{ $postmortem->publicUrl() }}" target="_blank" rel="noopener"><svg class="ico"><use href="#i-external"/></svg> {{ __('postmortems.share.link') }}</a>
                    @else
                        <button type="button" class="ss-btn ss-btn-secondary" popovertarget="pm-share" popovertargetaction="hide">{{ __('common.cancel') }}</button>
                        <form method="POST" action="{{ route('incidents.postmortem.share', $incident) }}">@csrf<button type="submit" class="ss-btn ss-btn-primary">{{ __('postmortems.share.make') }}</button></form>
                    @endif
                </div>
            @else
                <p class="m-0 text-sm text-muted">{{ __('postmortems.share.admin_only') }}</p>
            @endif
        </div>
    </div>
</x-layouts.app>
