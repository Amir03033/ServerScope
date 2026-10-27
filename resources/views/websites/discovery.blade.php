@php
    use App\Support\Time;

    $isAdmin = auth()->user()->can('admin');
    $running = $runs->getCollection()->contains(fn ($run) => $run->isRunning());
    $lines = fn (array $values) => implode("\n", $values);
    $statusPill = ['completed' => ['valid', 'Voltooid'], 'partial' => ['warning', 'Gedeeltelijk'], 'failed' => ['failed', 'Mislukt'], 'queued' => ['queued', 'In wachtrij'], 'running' => ['investigating', 'Bezig']];
@endphp

<x-layouts.app :title="'Pagina\'s ontdekken — '.$domain->displayName()">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a> <span>›</span> Ontdekken</x-slot:breadcrumb>
    <x-slot:subtitle>Vind pagina's via robots.txt, XML-sitemaps (ook sitemapindexen) en interne links. Alleen binnen {{ $domain->name }}; elk verzoek wordt gecontroleerd op een publiek adres.</x-slot:subtitle>
    <x-slot:actions>
        @if ($isAdmin)
            <form method="POST" action="{{ route('websites.discovery.run', $domain) }}" class="inline-form">
                @csrf
                <button class="button button-primary" type="submit" style="white-space:nowrap" @disabled($running)><svg class="ico"><use href="#i-search"/></svg> {{ $running ? 'Ontdekking loopt…' : 'Nu ontdekken' }}</button>
            </form>
        @endif
    </x-slot:actions>
    @if ($running)
        <x-slot:head><meta http-equiv="refresh" content="10"></x-slot:head>
    @endif

    @include('websites._tabs')

    @if ($pendingCount > 0)
        <div class="flash info" role="status"><svg class="ico"><use href="#i-search"/></svg><div><strong>{{ $pendingCount }} {{ $pendingCount === 1 ? 'pagina wacht' : "pagina's wachten" }} op goedkeuring.</strong> <a href="{{ route('websites.pages', [$domain, 'status' => 'pending']) }}">Beoordelen</a></div></div>
    @endif

    <div class="grid-2 split">
        <section class="panel">
            <div class="section-heading"><div><h2>Ontdekkingen</h2><p>Mislukte ontdekkingen veranderen nooit iets aan bestaande monitoring.</p></div></div>
            @if ($runs->isEmpty())
                <x-empty-state icon="i-search" title="Nog geen ontdekking uitgevoerd">Start een ontdekking om pagina's te vinden. Gevonden pagina's wachten standaard op goedkeuring.</x-empty-state>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Gestart</th><th>Status</th><th class="num">Gevonden</th><th class="num">Nieuw</th><th class="num">Overgeslagen</th><th class="num">Verzoeken</th></tr></thead>
                        <tbody>
                            @foreach ($runs as $run)
                                <tr>
                                    <td class="nowrap">{{ Time::format($run->created_at) }}<span class="cell-sub">{{ $run->triggeredBy?->name ?? 'Systeem' }}@if ($run->finished_at) · {{ max(0, (int) $run->started_at?->diffInSeconds($run->finished_at)) }} s @endif</span></td>
                                    <td><x-status :status="$statusPill[$run->status][0] ?? 'unknown'" :label="$statusPill[$run->status][1] ?? $run->status" />
                                        @if ($run->sources)<span class="cell-sub">@foreach ($run->sources as $source => $n){{ $source }}: {{ $n }} @endforeach</span>@endif</td>
                                    <td class="num">{{ $run->urls_found }}</td>
                                    <td class="num">{{ $run->pages_added }}</td>
                                    <td class="num">{{ $run->urls_skipped }}</td>
                                    <td class="num">{{ $run->requests_made }}</td>
                                </tr>
                                @if ($run->errors)
                                    <tr><td colspan="6" class="small"><details><summary>{{ count($run->errors) }} melding(en)</summary><ul style="margin:6px 0 0;padding-left:18px">@foreach ($run->errors as $error)<li class="break">{{ $error }}</li>@endforeach</ul></details></td></tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-pagination :paginator="$runs" />
            @endif
        </section>

        <section class="panel">
            <div class="section-heading"><div><h2>Instellingen</h2><p>Standaard veilig: maximaal {{ config('serverscope.websites.discovery_default_max_pages') }} pagina's, diepte 1, alleen {{ $domain->name }}, met goedkeuring.</p></div></div>
            @if ($isAdmin)
                <form method="POST" action="{{ route('websites.discovery.update', $domain) }}" class="stack" style="gap:12px">
                    @csrf @method('PUT')
                    <div class="form-cols">
                        <div @class(['form-field', 'has-error' => $errors->has('max_pages')])><label for="max_pages">Max. pagina's</label><input id="max_pages" name="max_pages" type="number" min="1" max="{{ config('serverscope.websites.discovery_max_pages_limit') }}" value="{{ old('max_pages', $settings['max_pages']) }}">@error('max_pages')<span class="form-error">{{ $message }}</span>@enderror</div>
                        <div @class(['form-field', 'has-error' => $errors->has('max_depth')])><label for="max_depth">Max. linkdiepte</label><input id="max_depth" name="max_depth" type="number" min="0" max="5" value="{{ old('max_depth', $settings['max_depth']) }}"><span class="form-help">0 = alleen sitemaps.</span>@error('max_depth')<span class="form-error">{{ $message }}</span>@enderror</div>
                        <div @class(['form-field', 'has-error' => $errors->has('default_interval')])><label for="default_interval">Interval nieuwe pagina's (min)</label><input id="default_interval" name="default_interval" type="number" min="1" max="1440" value="{{ old('default_interval', $settings['default_interval']) }}"></div>
                    </div>
                    <div class="form-field"><label for="sitemap_urls">Extra sitemap-URL's (één per regel)</label><textarea id="sitemap_urls" name="sitemap_urls" rows="2" placeholder="https://{{ $domain->name }}/sitemap_index.xml">{{ old('sitemap_urls', $lines($settings['sitemap_urls'])) }}</textarea>@error('sitemap_urls')<span class="form-error">{{ $message }}</span>@enderror</div>
                    <div class="form-field"><label for="allowed_patterns">Alleen deze paden (patronen, één per regel)</label><textarea id="allowed_patterns" name="allowed_patterns" rows="2" placeholder="/docs/*">{{ old('allowed_patterns', $lines($settings['allowed_patterns'])) }}</textarea></div>
                    <div class="form-field"><label for="excluded_patterns">Uitsluiten (patronen, één per regel)</label><textarea id="excluded_patterns" name="excluded_patterns" rows="2" placeholder="/wp-admin/*&#10;*.pdf">{{ old('excluded_patterns', $lines($settings['excluded_patterns'])) }}</textarea><span class="form-help">Patronen met * op pad en querystring.</span></div>
                    <label class="check"><input type="hidden" name="keep_query" value="0"><input type="checkbox" name="keep_query" value="1" @checked(old('keep_query', $settings['keep_query']))> Queryparameters behouden (trackingparameters worden altijd verwijderd)</label>
                    <label class="check"><input type="hidden" name="include_subdomains" value="0"><input type="checkbox" name="include_subdomains" value="1" @checked(old('include_subdomains', $settings['include_subdomains']))> Subdomeinen meenemen</label>
                    <label class="check"><input type="hidden" name="auto_monitor" value="0"><input type="checkbox" name="auto_monitor" value="1" @checked(old('auto_monitor', $settings['auto_monitor']))> Gevonden pagina's direct monitoren (zonder goedkeuring)</label>
                    <div @class(['form-field', 'has-error' => $errors->has('broad_confirmed')])>
                        <label class="check"><input type="checkbox" name="broad_confirmed" value="1"> Ik bevestig een bredere ontdekking dan de standaard</label>
                        <span class="form-help">Verplicht bij meer dan {{ config('serverscope.websites.discovery_default_max_pages') }} pagina's, diepte boven 2, subdomeinen of direct monitoren.</span>
                        @error('broad_confirmed')<span class="form-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-footer"><button class="button button-primary" type="submit">Opslaan</button></div>
                </form>
            @else
                <dl class="dl">
                    <dt>Max. pagina's / diepte</dt><dd>{{ $settings['max_pages'] }} / {{ $settings['max_depth'] }}</dd>
                    <dt>Subdomeinen</dt><dd>{{ $settings['include_subdomains'] ? 'ja' : 'nee' }}</dd>
                    <dt>Direct monitoren</dt><dd>{{ $settings['auto_monitor'] ? 'ja' : 'nee, met goedkeuring' }}</dd>
                </dl>
            @endif
        </section>
    </div>
</x-layouts.app>
