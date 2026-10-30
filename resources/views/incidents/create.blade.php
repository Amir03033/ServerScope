@php
    use App\Support\Label;
    use App\Support\Severity;
@endphp

<x-layouts.app :title="__('incidents.create.title')">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('incidents.index') }}">{{ __('incidents.title') }}</a> <span>›</span> {{ __('incidents.create.crumb') }}</x-slot:breadcrumb>
    <x-slot:subtitle>{{ __('incidents.create.subtitle') }}</x-slot:subtitle>
    <x-slot:actions><a href="{{ route('incidents.index') }}" class="ss-btn ss-btn-ghost"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('incidents.back') }}</a></x-slot:actions>

    <section class="ss-panel max-w-4xl">
        <form method="POST" action="{{ route('incidents.store') }}">
            @csrf
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="ss-label" for="title">{{ __('incidents.create.field_title') }}</label>
                    <input id="title" class="ss-input mt-1.5" name="title" type="text" maxlength="255" required value="{{ old('title') }}" @error('title') aria-invalid="true" aria-describedby="title-error" @enderror>
                    @error('title')<p id="title-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="ss-label" for="severity">{{ __('incidents.create.severity') }}</label>
                    <select id="severity" class="ss-input mt-1.5" name="severity" required>
                        @foreach (Severity::options() as $v => $l)<option value="{{ $v }}" @selected(old('severity') === $v)>{{ Label::of('severity', $v, Severity::LABELS) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="ss-label" for="subject">{{ __('incidents.create.subject') }}</label>
                    <select id="subject" class="ss-input mt-1.5" name="subject">
                        <option value="">{{ __('incidents.create.not_linked') }}</option>
                        <optgroup label="{{ __('incidents.create.servers') }}">@foreach ($servers as $s)<option value="server:{{ $s->id }}" @selected(old('subject') === 'server:'.$s->id)>{{ $s->name }}</option>@endforeach</optgroup>
                        <optgroup label="{{ __('incidents.create.domains') }}">@foreach ($domains as $d)<option value="domain:{{ $d->id }}" @selected(old('subject') === 'domain:'.$d->id)>{{ $d->displayName() }}</option>@endforeach</optgroup>
                    </select>
                </div>
                <div>
                    <label class="ss-label" for="assigned_to">{{ __('incidents.create.assign_to') }}</label>
                    <select id="assigned_to" class="ss-input mt-1.5" name="assigned_to"><option value="">{{ __('incidents.nobody') }}</option>@foreach ($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select>
                </div>
                <div class="sm:col-span-2">
                    <label class="ss-label" for="description">{{ __('incidents.create.description') }}</label>
                    <textarea id="description" class="ss-input mt-1.5 min-h-28" name="description" maxlength="5000">{{ old('description') }}</textarea>
                </div>
            </div>
            <div class="mt-6 flex flex-wrap gap-2 border-t border-line pt-5">
                <button class="ss-btn ss-btn-primary" type="submit">{{ __('incidents.create.submit') }}</button>
                <a class="ss-btn ss-btn-ghost" href="{{ route('incidents.index') }}">{{ __('incidents.cancel') }}</a>
            </div>
        </form>
    </section>
</x-layouts.app>
