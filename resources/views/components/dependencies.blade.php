@props(['resource'])

@php
    use App\Services\Dependencies\DependencyGraph;
    use App\Services\Dependencies\ResourceCatalog;

    $graph = app(DependencyGraph::class);
    $type = $resource->getMorphClass();
    $upstream = $graph->upstream($type, (int) $resource->getKey());
    $downstreamAll = $graph->descendants($type, (int) $resource->getKey());
    unset($downstreamAll[ResourceCatalog::key($resource)]);
    $direct = collect($graph->downstream($type, (int) $resource->getKey()))->reject(fn ($e) => $e['origin'] === 'structural' && $e['type'] === 'page');
    $pageCount = collect($graph->downstream($type, (int) $resource->getKey()))->where('type', 'page')->count();
    $inferred = $graph->inferred($resource);
    $isAdmin = auth()->user()?->can('admin');
    $link = function (string $t, int $id) {
        $model = ResourceCatalog::find($t, $id);
        $url = ResourceCatalog::url($model);
        $name = e(ResourceCatalog::name($model));

        return $url ? '<a href="'.e($url).'">'.$name.'</a>' : $name;
    };
@endphp

<section {{ $attributes->class('ss-panel') }}>
    <div class="ss-panel-head">
        <div><h2 class="ss-panel-title">{{ __('components.dependencies.title') }}</h2><p class="ss-panel-sub">{{ __('components.dependencies.text') }}</p></div>
        @if (Route::has('dependencies.index'))<a class="ss-btn ss-btn-secondary ss-btn-sm" href="{{ route('dependencies.index', ['dependent' => ResourceCatalog::key($resource)]) }}">{{ __('components.dependencies.manage') }}</a>@endif
    </div>

    <dl class="ss-dl">
        <dt>{{ __('components.dependencies.depends_on') }}</dt>
        <dd>
            @forelse ($upstream as $edge)
                <div>{!! $link($edge['type'], $edge['id']) !!} <span class="text-xs text-muted">{{ ResourceCatalog::typeLabel($edge['type']) }}{{ $edge['label'] ? ' · '.$edge['label'] : '' }}</span></div>
            @empty
                <span class="text-muted">{{ __('components.dependencies.none_confirmed') }}</span>
            @endforelse
        </dd>
        <dt>{{ __('components.dependencies.affected') }}</dt>
        <dd>
            @if ($direct->isEmpty() && $pageCount === 0)
                <span class="text-muted">{{ __('components.dependencies.nothing_known') }}</span>
            @else
                @foreach ($direct as $edge)
                    <div>{!! $link($edge['type'], $edge['id']) !!} <span class="text-xs text-muted">{{ ResourceCatalog::typeLabel($edge['type']) }}{{ $edge['label'] ? ' · '.$edge['label'] : '' }}</span></div>
                @endforeach
                @if ($pageCount > 0)<div>{{ trans_choice('components.dependencies.pages', $pageCount, ['count' => $pageCount]) }}</div>@endif
                @if (count($downstreamAll) > $direct->count() + $pageCount)
                    <div class="text-xs text-muted">{{ __('components.dependencies.total', ['count' => count($downstreamAll)]) }}</div>
                @endif
            @endif
        </dd>
    </dl>

    @if ($inferred !== [])
        <div class="mt-3">
            <strong class="text-sm">{{ __('components.dependencies.suggested') }}</strong>
            @foreach ($inferred as $suggestion)
                <div class="mt-1.5 flex flex-wrap items-center gap-2 text-sm">
                    <span>{!! $link($suggestion['type'], $suggestion['id']) !!} — {{ $suggestion['reason'] }}</span>
                    @if ($isAdmin)
                        <form method="POST" action="{{ route('dependencies.store') }}">
                            @csrf
                            @if ($suggestion['direction'] === 'upstream')
                                <input type="hidden" name="dependent" value="{{ ResourceCatalog::key($resource) }}">
                                <input type="hidden" name="dependency" value="{{ $suggestion['type'] }}:{{ $suggestion['id'] }}">
                            @else
                                <input type="hidden" name="dependent" value="{{ $suggestion['type'] }}:{{ $suggestion['id'] }}">
                                <input type="hidden" name="dependency" value="{{ ResourceCatalog::key($resource) }}">
                            @endif
                            <input type="hidden" name="label" value="draait op">
                            <button class="ss-btn ss-btn-secondary ss-btn-sm" type="submit">{{ __('components.dependencies.confirm') }}</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
