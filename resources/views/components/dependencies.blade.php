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

<section {{ $attributes->class('panel') }}>
    <div class="section-heading">
        <div><h2>Afhankelijkheden</h2><p>Bevestigde relaties worden gebruikt om incidenten te groeperen; voorgestelde relaties niet.</p></div>
        @if (Route::has('dependencies.index'))<a class="button button-sm" href="{{ route('dependencies.index', ['dependent' => ResourceCatalog::key($resource)]) }}">Beheren</a>@endif
    </div>

    <dl class="dl">
        <dt>Hangt af van</dt>
        <dd>
            @forelse ($upstream as $edge)
                <div>{!! $link($edge['type'], $edge['id']) !!} <span class="muted small">{{ ResourceCatalog::typeLabel($edge['type']) }}{{ $edge['label'] ? ' · '.$edge['label'] : '' }}</span></div>
            @empty
                <span class="muted">Geen bevestigde afhankelijkheden</span>
            @endforelse
        </dd>
        <dt>Mogelijk getroffen bij storing</dt>
        <dd>
            @if ($direct->isEmpty() && $pageCount === 0)
                <span class="muted">Niets bekend</span>
            @else
                @foreach ($direct as $edge)
                    <div>{!! $link($edge['type'], $edge['id']) !!} <span class="muted small">{{ ResourceCatalog::typeLabel($edge['type']) }}{{ $edge['label'] ? ' · '.$edge['label'] : '' }}</span></div>
                @endforeach
                @if ($pageCount > 0)<div>{{ $pageCount }} gemonitorde {{ $pageCount === 1 ? 'pagina' : "pagina's" }}</div>@endif
                @if (count($downstreamAll) > $direct->count() + $pageCount)
                    <div class="muted small">In totaal {{ count($downstreamAll) }} resources, inclusief indirecte afhankelijkheden.</div>
                @endif
            @endif
        </dd>
    </dl>

    @if ($inferred !== [])
        <div style="margin-top:12px">
            <strong class="small">Voorgestelde relaties (niet bevestigd)</strong>
            @foreach ($inferred as $suggestion)
                <div class="small" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px">
                    <span>{!! $link($suggestion['type'], $suggestion['id']) !!} — {{ $suggestion['reason'] }}</span>
                    @if ($isAdmin)
                        <form method="POST" action="{{ route('dependencies.store') }}" class="inline-form">
                            @csrf
                            @if ($suggestion['direction'] === 'upstream')
                                <input type="hidden" name="dependent" value="{{ ResourceCatalog::key($resource) }}">
                                <input type="hidden" name="dependency" value="{{ $suggestion['type'] }}:{{ $suggestion['id'] }}">
                            @else
                                <input type="hidden" name="dependent" value="{{ $suggestion['type'] }}:{{ $suggestion['id'] }}">
                                <input type="hidden" name="dependency" value="{{ ResourceCatalog::key($resource) }}">
                            @endif
                            <input type="hidden" name="label" value="draait op">
                            <button class="button button-sm" type="submit">Bevestigen</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</section>
