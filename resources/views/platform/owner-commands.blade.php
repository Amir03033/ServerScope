<x-layouts.app :title="__('owner.title')">
    <x-slot:subtitle>{{ __('owner.subtitle') }}</x-slot:subtitle>

    <div class="grid gap-4">
        @foreach ($groups as $group => $ids)
            <section class="ss-panel" aria-labelledby="g-{{ $group }}">
                <div class="ss-panel-head"><div><h2 id="g-{{ $group }}" class="ss-panel-title">{{ __('owner.groups.'.$group) }}</h2></div></div>
                <div class="grid gap-4 p-4">
                    @foreach ($ids as $id)
                        @php([$cmd, $app, $danger] = $commands[$id])
                        <div data-command="{{ $id }}">
                            <strong>{{ __('owner.cmd.'.$id.'.title') }}</strong>
                            <span class="ss-chip">{{ $app === 'info' ? __('owner.in_info') : __('owner.in_app') }}</span>
                            @if ($danger)<span class="ss-chip" title="{{ __('owner.danger_hint') }}">{{ __('owner.danger') }}</span>@endif
                            <pre class="mt-1.5 rounded-md border border-line p-2 text-xs" style="overflow-x:auto"><code>{{ $cmd }}</code></pre>
                            <p class="m-0 mt-1 text-sm text-muted">{{ __('owner.cmd.'.$id.'.when') }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</x-layouts.app>
