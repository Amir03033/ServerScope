@php
    use App\Http\Requests\PageRequest;
    use App\Support\Label;
    use App\Support\Severity;

    $editing = $page->exists;
    $value = fn (string $key, $default = null) => old($key, $page->{$key} ?? $default);
    $checked = fn (string $key, bool $default) => (bool) old($key, $page->exists ? $page->{$key} : $default);
    $headerText = old('header_assertions_input', PageRequest::headerAssertionsText($page->header_assertions));
    $exclusions = old('content_exclusions', $page->content_exclusions ?? []);
    $exclusions = is_array($exclusions) ? implode("\n", $exclusions) : $exclusions;
    $monitoredHeaders = old('monitored_headers', $page->monitored_headers ?? []);
    $monitoredHeaders = is_array($monitoredHeaders) ? implode(', ', $monitoredHeaders) : $monitoredHeaders;
    $field = fn (string ...$names) => collect($names)->contains(fn ($name) => $errors->has($name)) ? 'aria-invalid=true aria-describedby='.$names[0].'-error' : '';
    $headerName = __('websites.form.header_name');
@endphp

<x-layouts.app :title="$editing ? __('websites.form.title_edit') : __('websites.form.title_create')">
    <x-slot:breadcrumb><span>›</span> <a href="{{ route('domains.show', $domain) }}">{{ $domain->displayName() }}</a> <span>›</span> <a href="{{ route('websites.pages', $domain) }}">{{ __('websites.pages_crumb') }}</a> <span>›</span> {{ $editing ? __('websites.form.crumb_edit') : __('websites.form.crumb_create') }}</x-slot:breadcrumb>
    <x-slot:subtitle>{{ $editing ? $page->url : __('websites.form.subtitle_create', ['domain' => $domain->displayName()]) }}</x-slot:subtitle>

    <form method="POST" action="{{ $editing ? route('pages.update', $page) : route('websites.pages.store', $domain) }}" class="grid max-w-4xl gap-4" novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <section class="ss-panel" aria-labelledby="form-check">
            <div class="grid gap-5 sm:grid-cols-2">
                @if ($editing)
                    <div class="sm:col-span-2">
                        <label class="ss-label" for="url">{{ __('websites.form.url') }}</label>
                        <input id="url" class="ss-input mt-1.5" name="url" type="url" required maxlength="2048" value="{{ old('url', $page->url) }}" {{ $field('url') }}>
                        @error('url')<p id="url-error" class="ss-error">{{ $message }}</p>@enderror
                    </div>
                @else
                    <div class="sm:col-span-2">
                        <label class="ss-label" for="urls">{{ __('websites.form.urls') }}</label>
                        <textarea id="urls" class="ss-input mt-1.5 font-mono text-[13px]" name="urls" required rows="6" placeholder="{{ $domain->scheme }}://{{ $domain->name }}/&#10;/prijzen&#10;/docs&#10;/api/health" {{ $field('urls') }}>{{ old('urls') }}</textarea>
                        <p class="ss-help">{{ __('websites.form.urls_help', ['domain' => $domain->name, 'max' => PageRequest::MAX_URLS_PER_REQUEST]) }}</p>
                        @error('urls')<p id="urls-error" class="ss-error">{{ $message }}</p>@enderror
                    </div>
                @endif

                <div class="border-t border-line pt-5 sm:col-span-2">
                    <h2 id="form-check" class="m-0 text-sm font-semibold">{{ __('websites.form.check_title') }}</h2>
                    <p class="ss-panel-sub">{{ __('websites.form.check_text') }}</p>
                </div>

                @foreach ([['check_interval_minutes', 5, 1, 1440], ['timeout_seconds', 10, 2, 30], ['failure_threshold', 2, 1, 10], ['recovery_threshold', 2, 1, 10]] as [$key, $default, $min, $max])
                    <div>
                        <label class="ss-label" for="{{ $key }}">{{ __('websites.form.'.$key) }} *</label>
                        <input id="{{ $key }}" class="ss-input mt-1.5" name="{{ $key }}" type="number" min="{{ $min }}" max="{{ $max }}" required value="{{ $value($key, $default) }}" {{ $field($key) }}>
                        @error($key)<p id="{{ $key }}-error" class="ss-error">{{ $message }}</p>@enderror
                    </div>
                @endforeach

                <div>
                    <label class="ss-label" for="expected_status_codes">{{ __('websites.form.status_codes') }}</label>
                    <input id="expected_status_codes" class="ss-input mt-1.5" name="expected_status_codes" type="text" maxlength="100" placeholder="200-399" value="{{ $value('expected_status_codes') }}" {{ $field('expected_status_codes') }}>
                    <p class="ss-help">{{ __('websites.form.status_codes_help') }}</p>
                    @error('expected_status_codes')<p id="expected_status_codes-error" class="ss-error">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="ss-label" for="max_response_ms">{{ __('websites.form.max_response') }}</label>
                    <input id="max_response_ms" class="ss-input mt-1.5" name="max_response_ms" type="number" min="50" max="60000" value="{{ $value('max_response_ms') }}" placeholder="{{ __('websites.form.optional') }}" {{ $field('max_response_ms') }}>
                    <p class="ss-help">{{ __('websites.form.max_response_help') }}</p>
                    @error('max_response_ms')<p id="max_response_ms-error" class="ss-error">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="ss-check"><input type="hidden" name="follow_redirects" value="0"><input type="checkbox" name="follow_redirects" value="1" @checked($checked('follow_redirects', true))> {{ __('websites.form.follow_redirects') }}</label>
                </div>

                <div class="sm:col-span-2">
                    <label class="ss-label" for="expected_final_url">{{ __('websites.form.final_url') }}</label>
                    <input id="expected_final_url" class="ss-input mt-1.5" name="expected_final_url" type="url" maxlength="2048" value="{{ $value('expected_final_url') }}" placeholder="{{ __('websites.form.optional') }}" {{ $field('expected_final_url') }}>
                    @error('expected_final_url')<p id="expected_final_url-error" class="ss-error">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <section class="ss-panel" aria-labelledby="form-content">
            <h2 id="form-content" class="ss-panel-title">{{ __('websites.form.content_title') }}</h2>
            <p class="ss-panel-sub mb-4">{{ __('websites.form.content_text') }}</p>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label class="ss-label" for="keyword_present">{{ __('websites.form.keyword_present') }}</label>
                    <input id="keyword_present" class="ss-input mt-1.5" name="keyword_present" type="text" maxlength="255" value="{{ $value('keyword_present') }}">
                </div>
                <div>
                    <label class="ss-label" for="keyword_absent">{{ __('websites.form.keyword_absent') }}</label>
                    <input id="keyword_absent" class="ss-input mt-1.5" name="keyword_absent" type="text" maxlength="255" value="{{ $value('keyword_absent') }}" placeholder="{{ __('websites.form.keyword_absent_placeholder') }}">
                </div>

                <div>
                    <label class="ss-label" for="header_assertions">{{ __('websites.form.header_assertions') }}</label>
                    <textarea id="header_assertions" class="ss-input mt-1.5 font-mono text-[13px]" name="header_assertions" rows="4" placeholder="Strict-Transport-Security&#10;Content-Type ~ text/html&#10;!X-Powered-By" {{ $field('header_assertions', 'header_assertions.*.name') }}>{{ $headerText }}</textarea>
                    <p class="ss-help">{!! __('websites.form.header_assertions_help', [
                        'present' => '<code>'.e($headerName).'</code>',
                        'absent' => '<code>!'.e($headerName).'</code>',
                        'equals' => '<code>'.e($headerName).' = '.e(__('websites.form.header_value')).'</code>',
                        'contains' => '<code>'.e($headerName).' ~ '.e(__('websites.form.header_part')).'</code>',
                    ]) !!}</p>
                    @if ($errors->has('header_assertions') || $errors->has('header_assertions.*.name'))
                        <div id="header_assertions-error">
                            @error('header_assertions')<p class="ss-error">{{ $message }}</p>@enderror
                            @error('header_assertions.*.name')<p class="ss-error">{{ $message }}</p>@enderror
                        </div>
                    @endif
                </div>

                <div>
                    <label class="ss-label" for="monitored_headers">{{ __('websites.form.monitored_headers') }}</label>
                    <input id="monitored_headers" class="ss-input mt-1.5" name="monitored_headers" type="text" value="{{ $monitoredHeaders }}" placeholder="content-security-policy, strict-transport-security" @error('monitored_headers.*') aria-invalid="true" aria-describedby="monitored_headers-error" @enderror>
                    <p class="ss-help">{{ __('websites.form.monitored_headers_help') }}</p>
                    @error('monitored_headers.*')<p id="monitored_headers-error" class="ss-error">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="ss-check"><input type="hidden" name="content_monitoring" value="0"><input type="checkbox" name="content_monitoring" value="1" @checked($checked('content_monitoring', false))> {{ __('websites.form.content_monitoring') }}</label>
                    <p class="ss-help">{{ __('websites.form.content_monitoring_help') }}</p>
                </div>

                <div>
                    <label class="ss-label" for="content_selector">{{ __('websites.form.content_selector') }}</label>
                    <input id="content_selector" class="ss-input mt-1.5" name="content_selector" type="text" maxlength="255" value="{{ $value('content_selector') }}" placeholder="{{ __('websites.form.content_selector_placeholder') }}" {{ $field('content_selector') }}>
                    @error('content_selector')<p id="content_selector-error" class="ss-error">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label class="ss-label" for="content_exclusions">{{ __('websites.form.content_exclusions') }}</label>
                    <textarea id="content_exclusions" class="ss-input mt-1.5 font-mono text-[13px]" name="content_exclusions" rows="3" placeholder=".advertentie&#10;#laatste-nieuws">{{ $exclusions }}</textarea>
                </div>
            </div>
        </section>

        <section class="ss-panel" aria-labelledby="form-alerts">
            <h2 id="form-alerts" class="ss-panel-title mb-4">{{ __('websites.form.alerts_title') }}</h2>
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="ss-check"><input type="hidden" name="alerts_enabled" value="0"><input type="checkbox" name="alerts_enabled" value="1" @checked($checked('alerts_enabled', true))> {{ __('websites.form.alerts_enabled') }}</label>
                    <p class="ss-help">{{ __('websites.form.alerts_enabled_help') }}</p>
                </div>

                <div>
                    <label class="ss-label" for="alert_severity">{{ __('websites.form.alert_severity') }}</label>
                    <select id="alert_severity" class="ss-input mt-1.5" name="alert_severity" {{ $field('alert_severity') }}>@foreach (Severity::options() as $v => $l)<option value="{{ $v }}" @selected($value('alert_severity', 'high') === $v)>{{ Label::of('severity', $v, Severity::LABELS) }}</option>@endforeach</select>
                </div>

                <div>
                    <label class="ss-label" for="sla_target">{{ __('websites.form.sla') }}</label>
                    <input id="sla_target" class="ss-input mt-1.5" name="sla_target" type="text" inputmode="decimal" value="{{ $value('sla_target') }}" placeholder="{{ __('websites.form.sla_placeholder') }}" {{ $field('sla_target') }}>
                    @error('sla_target')<p id="sla_target-error" class="ss-error">{{ $message }}</p>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="ss-check"><input type="hidden" name="notify_content_changes" value="0"><input type="checkbox" name="notify_content_changes" value="1" @checked($checked('notify_content_changes', false))> {{ __('websites.form.notify_content_changes') }}</label>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap gap-2">
            <button class="ss-btn ss-btn-primary" type="submit">{{ $editing ? __('websites.form.save') : __('websites.form.add') }}</button>
            <a href="{{ $editing ? route('pages.show', $page) : route('websites.pages', $domain) }}" class="ss-btn ss-btn-ghost">{{ __('websites.form.cancel') }}</a>
        </div>
    </form>
</x-layouts.app>
