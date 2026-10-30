@php
    use App\Support\Contrast;

    $editing = $page->exists;
    $components = old('components', $page->components?->map(fn ($c) => ['subject' => $c->subject_type.':'.$c->subject_id, 'display_name' => $c->display_name, 'description' => $c->description])->all() ?? []);
    $components = array_values($components ?: [['subject' => '', 'display_name' => '', 'description' => '']]);
    $field = fn (string $name, ?string $id = null) => $errors->has($name) ? 'aria-invalid=true aria-describedby='.($id ?? $name).'-error' : '';

    // Where the chosen accent is too pale or too dark to read as text.
    $accent = old('accent_color', $page->accent_color);
    $weak = Contrast::isHex($accent) ? array_keys(array_filter([
        'light' => Contrast::textOn($accent, Contrast::SURFACE_LIGHT) === null,
        'dark' => Contrast::textOn($accent, Contrast::SURFACE_DARK) === null,
    ])) : [];
@endphp

<x-layouts.app :title="$editing ? __('status_pages.form.title_edit') : __('status_pages.form.title_create')">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('status-pages.index') }}">{{ __('status_pages.title') }}</a> <span>›</span> {{ $editing ? $page->name : __('status_pages.form.crumb_new') }}</x-slot:breadcrumb>
    <x-slot:actions>
        @if ($editing)<a class="ss-btn ss-btn-secondary" href="{{ route('status.show', $page->slug) }}" target="_blank" rel="noopener"><svg class="ico"><use href="#i-status"/></svg> {{ __('status_pages.form.view') }}</a>@endif
        <a class="ss-btn ss-btn-ghost" href="{{ route('status-pages.index') }}"><svg class="ico"><use href="#i-arrow-left"/></svg> {{ __('status_pages.form.back') }}</a>
    </x-slot:actions>

    <div class="grid max-w-5xl gap-4">
        <div class="ss-alert ss-alert-info"><svg class="ico"><use href="#i-shield"/></svg><div>{{ __('status_pages.form.privacy') }}</div></div>

        <form method="POST" action="{{ $editing ? route('status-pages.update', $page) : route('status-pages.store') }}" class="grid gap-4">
            @csrf
            @if ($editing) @method('PUT') @endif

            <section class="ss-panel" aria-labelledby="page-title">
                <h2 id="page-title" class="ss-panel-title mb-4">{{ __('status_pages.form.page_title') }}</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label class="ss-label" for="name">{{ __('status_pages.form.name') }}</label>
                        <input id="name" class="ss-input mt-1.5" name="name" maxlength="100" required value="{{ old('name', $page->name) }}" placeholder="{{ __('status_pages.form.name_placeholder') }}" {{ $field('name') }}>
                        @error('name')<p id="name-error" class="ss-error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="ss-label" for="slug">{{ __('status_pages.form.slug') }}</label>
                        <input id="slug" class="ss-input mt-1.5" name="slug" maxlength="60" value="{{ old('slug', $page->slug) }}" placeholder="{{ __('status_pages.form.slug_placeholder') }}" {{ $field('slug') }}>
                        <p class="ss-help [overflow-wrap:anywhere]">{{ url('/status') }}/<em>{{ __('status_pages.form.slug_word') }}</em></p>
                        @error('slug')<p id="slug-error" class="ss-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="ss-label" for="description">{{ __('status_pages.form.description') }}</label>
                        <textarea id="description" class="ss-input mt-1.5 min-h-20" name="description" maxlength="1000">{{ old('description', $page->description) }}</textarea>
                    </div>
                    <div>
                        <label class="ss-label" for="accent_color">{{ __('status_pages.form.accent') }}</label>
                        <input id="accent_color" class="ss-input mt-1.5 font-mono" name="accent_color" type="text" value="{{ $accent }}" pattern="#[0-9a-fA-F]{6}" {{ $field('accent_color') }}>
                        <p class="ss-help">{{ __('status_pages.form.accent_help') }}</p>
                        @if ($weak !== [])
                            <p class="ss-help ss-text-warn">{{ __('status_pages.form.accent_warning', ['themes' => count($weak) === 2 ? __('status_pages.form.theme_both') : ($weak[0] === 'light' ? __('status_pages.form.theme_light') : __('status_pages.form.theme_dark'))]) }}</p>
                        @endif
                        @error('accent_color')<p id="accent_color-error" class="ss-error">{{ $message }}</p>@enderror
                    </div>
                    <fieldset class="m-0 grid content-start gap-2.5 border-0 p-0">
                        <legend class="sr-only">{{ __('status_pages.form.page_title') }}</legend>
                        @foreach (['is_public', 'show_uptime', 'show_incident_titles'] as $key)
                            <label class="ss-check items-start"><input type="hidden" name="{{ $key }}" value="0"><input class="mt-0.5" type="checkbox" name="{{ $key }}" value="1" @checked(old($key, $page->{$key}))> <span>{{ __('status_pages.form.options.'.$key) }}</span></label>
                        @endforeach
                    </fieldset>
                </div>
            </section>

            <section class="ss-panel" aria-labelledby="components-title">
                <div class="ss-panel-head"><div><h2 id="components-title" class="ss-panel-title">{{ __('status_pages.form.components_title') }}</h2><p class="ss-panel-sub">{{ __('status_pages.form.components_text') }}</p></div></div>
                <div class="grid gap-3" data-component-rows>
                    @foreach ($components as $i => $component)
                        <div class="grid items-end gap-3 border-b border-line pb-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]" data-component-row>
                            <div>
                                <label class="ss-label" for="component-{{ $i }}-subject">{{ __('status_pages.form.monitor') }}</label>
                                <select id="component-{{ $i }}-subject" class="ss-input mt-1.5" name="components[{{ $i }}][subject]" {{ $field("components.$i.subject", "component-$i-subject") }}>
                                    <option value="">{{ __('status_pages.form.choose') }}</option>
                                    <optgroup label="{{ __('status_pages.form.domains') }}">@foreach ($domains as $d)<option value="domain:{{ $d->id }}" @selected(($component['subject'] ?? '') === 'domain:'.$d->id)>{{ $d->displayName() }}</option>@endforeach</optgroup>
                                    <optgroup label="{{ __('status_pages.form.servers') }}">@foreach ($servers as $s)<option value="server:{{ $s->id }}" @selected(($component['subject'] ?? '') === 'server:'.$s->id)>{{ $s->name }}</option>@endforeach</optgroup>
                                </select>
                                @error("components.$i.subject")<p id="component-{{ $i }}-subject-error" class="ss-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="ss-label" for="component-{{ $i }}-name">{{ __('status_pages.form.public_name') }}</label>
                                <input id="component-{{ $i }}-name" class="ss-input mt-1.5" name="components[{{ $i }}][display_name]" maxlength="100" value="{{ $component['display_name'] ?? '' }}" placeholder="{{ __('status_pages.form.public_name_placeholder') }}" {{ $field("components.$i.display_name", "component-$i-name") }}>
                                @error("components.$i.display_name")<p id="component-{{ $i }}-name-error" class="ss-error">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="ss-label" for="component-{{ $i }}-description">{{ __('status_pages.form.component_description') }}</label>
                                <input id="component-{{ $i }}-description" class="ss-input mt-1.5" name="components[{{ $i }}][description]" maxlength="255" value="{{ $component['description'] ?? '' }}">
                            </div>
                            <button type="button" class="ss-btn ss-btn-ghost ss-btn-icon" data-component-remove aria-label="{{ __('status_pages.form.remove') }}" title="{{ __('status_pages.form.remove') }}"><svg class="ico"><use href="#i-x"/></svg></button>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="ss-btn ss-btn-secondary ss-btn-sm mt-3" data-component-add><svg class="ico"><use href="#i-plus"/></svg> {{ __('status_pages.form.add_component') }}</button>
            </section>

            <div><button class="ss-btn ss-btn-primary" type="submit">{{ $editing ? __('status_pages.form.save') : __('status_pages.form.title_create') }}</button></div>
        </form>

        @if ($editing)
            <section class="ss-panel ss-panel-danger mt-4" aria-labelledby="delete-title">
                <h2 id="delete-title" class="ss-panel-title mb-3">{{ __('status_pages.form.delete_title') }}</h2>
                <form method="POST" action="{{ route('status-pages.destroy', $page) }}" data-confirm="{{ __('status_pages.form.delete_confirm') }}">
                    @csrf @method('DELETE')
                    <button class="ss-btn ss-btn-danger" type="submit"><svg class="ico"><use href="#i-trash"/></svg> {{ __('status_pages.form.delete') }}</button>
                </form>
            </section>
        @endif
    </div>
</x-layouts.app>
