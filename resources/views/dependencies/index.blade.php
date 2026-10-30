@php
    use App\Services\Dependencies\ResourceCatalog;
    use App\Support\Label;
    use App\Support\Time;

    $typeLabel = fn (?string $type) => Label::of('resource_type', $type, ResourceCatalog::TYPES);
    // Option groups are keyed by the type label in the catalog; show them in the active language.
    $groupLabel = fn (string $group) => ($type = array_search($group, ResourceCatalog::TYPES, true)) !== false ? $typeLabel($type) : $group;
    $field = fn (string $name) => $errors->has($name) ? 'aria-invalid=true aria-describedby='.$name.'-error' : '';
@endphp

<x-layouts.app :title="__('dependencies.title')">
    <x-slot:subtitle>{{ __('dependencies.subtitle') }}</x-slot:subtitle>

    <div class="grid gap-4">
        @can('admin')
            <section class="ss-panel" aria-labelledby="add-title">
                <div class="ss-panel-head"><div><h2 id="add-title" class="ss-panel-title">{{ __('dependencies.add_title') }}</h2><p class="ss-panel-sub">{!! __('dependencies.add_text', ['resource' => '<em>'.e(__('dependencies.resource_word')).'</em>', 'dependency' => '<em>'.e(__('dependencies.dependency_word')).'</em>']) !!}</p></div></div>
                @if ($options === [])
                    <x-empty-state icon="i-link" :title="__('dependencies.no_resources_title')">{{ __('dependencies.no_resources_text') }}</x-empty-state>
                @else
                    <form method="POST" action="{{ route('dependencies.store') }}" class="grid gap-5 sm:grid-cols-2">
                        @csrf
                        @foreach (['dependent' => 'resource', 'dependency' => 'depends_on'] as $name => $label)
                            <div>
                                <label class="ss-label" for="{{ $name }}">{{ __('dependencies.'.$label) }}</label>
                                <select id="{{ $name }}" class="ss-input mt-1.5" name="{{ $name }}" required {{ $field($name) }}>
                                    <option value="">{{ __('dependencies.choose') }}</option>
                                    @foreach ($options as $group => $items)
                                        <optgroup label="{{ $groupLabel($group) }}">
                                            @foreach ($items as $key => $itemName)<option value="{{ $key }}" @selected(old($name, request($name)) === $key)>{{ $itemName }}</option>@endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                @error($name)<p id="{{ $name }}-error" class="ss-error">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                        <div>
                            <label class="ss-label" for="label">{{ __('dependencies.label') }}</label>
                            <input id="label" class="ss-input mt-1.5" type="text" name="label" maxlength="100" value="{{ old('label') }}" placeholder="{{ __('dependencies.label_placeholder') }}">
                        </div>
                        <div>
                            <label class="ss-label" for="notes">{{ __('dependencies.notes') }}</label>
                            <input id="notes" class="ss-input mt-1.5" type="text" name="notes" maxlength="2000" value="{{ old('notes') }}">
                        </div>
                        <div class="sm:col-span-2"><button class="ss-btn ss-btn-primary" type="submit"><svg class="ico"><use href="#i-plus"/></svg> {{ __('dependencies.submit') }}</button></div>
                    </form>
                @endif
            </section>
        @endcan

        <section class="ss-panel" aria-labelledby="confirmed-title">
            <div class="ss-panel-head"><div><h2 id="confirmed-title" class="ss-panel-title">{{ __('dependencies.confirmed_title') }}</h2></div></div>
            @if ($dependencies->isEmpty())
                <x-empty-state icon="i-link" :title="__('dependencies.empty_title')">{{ __('dependencies.empty_text') }}</x-empty-state>
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table ss-table-cards">
                        <thead><tr><th scope="col">{{ __('dependencies.col_resource') }}</th><th scope="col"><span class="sr-only">→</span></th><th scope="col">{{ __('dependencies.col_depends_on') }}</th><th scope="col">{{ __('dependencies.col_label') }}</th><th scope="col">{{ __('dependencies.col_created') }}</th><th scope="col"><span class="sr-only">{{ __('dependencies.col_actions') }}</span></th></tr></thead>
                        <tbody>
                            @foreach ($dependencies as $dependency)
                                <tr>
                                    <td class="is-primary">
                                        @if ($url = ResourceCatalog::url($dependency->dependent))<a class="font-semibold text-fg no-underline hover:text-accent" href="{{ $url }}">{{ ResourceCatalog::name($dependency->dependent) }}</a>@else{{ ResourceCatalog::name($dependency->dependent) }}@endif
                                        <span class="block text-xs text-muted">{{ $typeLabel($dependency->dependent_type) }}</span>
                                    </td>
                                    <td class="is-actions text-muted" aria-hidden="true">→</td>
                                    <td data-label="{{ __('dependencies.col_depends_on') }}">
                                        <span>
                                            @if ($url = ResourceCatalog::url($dependency->dependency))<a class="text-accent" href="{{ $url }}">{{ ResourceCatalog::name($dependency->dependency) }}</a>@else{{ ResourceCatalog::name($dependency->dependency) }}@endif
                                            <span class="block text-xs text-muted">{{ $typeLabel($dependency->dependency_type) }}</span>
                                        </span>
                                    </td>
                                    <td data-label="{{ __('dependencies.col_label') }}" class="text-sm"><span>{{ $dependency->label ?? '—' }}@if ($dependency->notes)<span class="block text-xs text-muted">{{ $dependency->notes }}</span>@endif</span></td>
                                    <td data-label="{{ __('dependencies.col_created') }}" class="whitespace-nowrap text-sm"><span>{{ Time::dateTime($dependency->created_at) }}<span class="block text-xs text-muted">{{ $dependency->creator?->name ?? '—' }}</span></span></td>
                                    <td class="is-actions">
                                        @can('admin')
                                            <form method="POST" action="{{ route('dependencies.destroy', $dependency) }}" class="flex justify-end" data-confirm="{{ __('dependencies.delete_confirm') }}">
                                                @csrf @method('DELETE')
                                                <button class="ss-btn ss-btn-danger ss-btn-sm" type="submit">{{ __('dependencies.delete') }}</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-pagination :paginator="$dependencies" />
            @endif
        </section>
    </div>
</x-layouts.app>
