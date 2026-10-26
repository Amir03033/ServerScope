<?php

namespace App\Http\Requests;

use App\Models\Domain;
use App\Models\MonitoredPage;
use App\Monitoring\Net\BlockedTargetException;
use App\Monitoring\Net\TargetGuard;
use App\Services\Websites\ContentFingerprint;
use App\Services\Websites\DiscoveryScope;
use App\Services\Websites\UrlNormalizer;
use App\Support\Severity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Settings of a monitored page. On create, `urls` holds one or more URLs
 * (one per line); on update, `url` holds the single URL.
 *
 * Header assertions, one per line:
 *   Name            header must be present
 *   !Name           header must be absent
 *   Name = value    exact value
 *   Name ~ value    value contains (case-insensitive)
 */
class PageRequest extends FormRequest
{
    public const MAX_URLS_PER_REQUEST = 50;

    /** @var array<int, string> */
    public array $normalizedUrls = [];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('admin');
    }

    protected function prepareForValidation(): void
    {
        $lines = fn ($value) => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value) ?: []), fn ($l) => $l !== ''));

        $this->merge([
            'expected_status_codes' => $this->filled('expected_status_codes') ? preg_replace('/\s+/', '', (string) $this->expected_status_codes) : null,
            'expected_final_url' => $this->filled('expected_final_url') ? trim((string) $this->expected_final_url) : null,
            'keyword_present' => $this->filled('keyword_present') ? (string) $this->keyword_present : null,
            'keyword_absent' => $this->filled('keyword_absent') ? (string) $this->keyword_absent : null,
            'max_response_ms' => $this->filled('max_response_ms') ? $this->max_response_ms : null,
            'content_selector' => $this->filled('content_selector') ? trim((string) $this->content_selector) : null,
            'content_exclusions' => is_array($this->content_exclusions) ? $this->content_exclusions : $lines($this->content_exclusions),
            'header_assertions_input' => is_string($this->header_assertions) ? $this->header_assertions : null,
            'header_assertions' => is_array($this->header_assertions) ? $this->header_assertions : self::parseHeaderAssertions($lines($this->header_assertions)),
            'monitored_headers' => is_array($this->monitored_headers) ? $this->monitored_headers : array_values(array_filter(array_map(fn ($h) => strtolower(trim($h)), explode(',', (string) $this->monitored_headers)))),
            'sla_target' => $this->filled('sla_target') ? str_replace(',', '.', (string) $this->sla_target) : null,
            ...collect(['follow_redirects', 'content_monitoring', 'alerts_enabled', 'notify_content_changes'])
                ->mapWithKeys(fn ($key) => [$key => $this->boolean($key)])->all(),
        ]);
    }

    public function rules(): array
    {
        $min = (int) config('serverscope.monitoring.min_interval_minutes', 1);

        return [
            'urls' => [$this->isMethod('post') ? 'required' : 'prohibited', 'string', 'max:20000'],
            'url' => [$this->isMethod('post') ? 'prohibited' : 'required', 'string', 'max:2048'],
            'check_interval_minutes' => ['required', 'integer', "between:{$min},1440"],
            'timeout_seconds' => ['required', 'integer', 'between:2,30'],
            'failure_threshold' => ['required', 'integer', 'between:1,10'],
            'recovery_threshold' => ['required', 'integer', 'between:1,10'],
            'expected_status_codes' => ['nullable', 'string', 'max:100', 'regex:/^\d{3}(-\d{3})?(,\d{3}(-\d{3})?)*$/'],
            'max_response_ms' => ['nullable', 'integer', 'between:50,60000'],
            'follow_redirects' => ['boolean'],
            'expected_final_url' => ['nullable', 'string', 'max:2048'],
            'keyword_present' => ['nullable', 'string', 'max:255'],
            'keyword_absent' => ['nullable', 'string', 'max:255'],
            'header_assertions' => ['array', 'max:20'],
            'header_assertions.*.name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/'],
            'header_assertions.*.mode' => ['required', Rule::in(['present', 'absent', 'equals', 'contains'])],
            'header_assertions.*.value' => ['nullable', 'string', 'max:500'],
            'monitored_headers' => ['array', 'max:20'],
            'monitored_headers.*' => ['string', 'max:100', 'regex:/^[a-z0-9!#$%&\'*+.^_`|~-]+$/'],
            'content_monitoring' => ['boolean'],
            'content_selector' => ['nullable', 'string', 'max:255'],
            'content_exclusions' => ['array', 'max:20'],
            'content_exclusions.*' => ['string', 'max:255'],
            'alerts_enabled' => ['boolean'],
            'alert_severity' => ['required', Rule::in(Severity::ALL)],
            'notify_content_changes' => ['boolean'],
            'sla_target' => ['nullable', 'numeric', 'between:50,100'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Domain $domain */
                $domain = $this->route('domain') ?? $this->route('page')?->domain;
                $scope = new DiscoveryScope($domain, ['allowed_patterns' => [], 'excluded_patterns' => [], 'include_subdomains' => true, 'keep_query' => true]);
                $raw = $this->isMethod('post') ? preg_split('/\R/', (string) $this->input('urls')) : [$this->input('url')];
                $raw = array_values(array_filter(array_map('trim', $raw ?: []), fn ($u) => $u !== ''));

                if (count($raw) > self::MAX_URLS_PER_REQUEST) {
                    $validator->errors()->add('urls', 'Voeg maximaal '.self::MAX_URLS_PER_REQUEST.' URL\'s tegelijk toe.');

                    return;
                }

                $field = $this->isMethod('post') ? 'urls' : 'url';
                $base = $domain->scheme.'://'.$domain->name.'/';

                foreach ($raw as $input) {
                    // Paths such as "/pricing" are relative to the website.
                    $normalized = UrlNormalizer::normalize($input, str_starts_with($input, '/') ? $base : null, keepQuery: true);

                    if ($normalized === null) {
                        $validator->errors()->add($field, "Ongeldige URL: {$input} (alleen http en https, zonder inloggegevens).");

                        continue;
                    }

                    if (! $scope->hostAllowed(UrlNormalizer::host($normalized))) {
                        $validator->errors()->add($field, "{$input} hoort niet bij {$domain->displayName()} (alleen dezelfde host of subdomeinen).");

                        continue;
                    }

                    try {
                        app(TargetGuard::class)->resolve($normalized);
                    } catch (BlockedTargetException $exception) {
                        $validator->errors()->add($field, "{$input}: {$exception->getMessage()}");

                        continue;
                    }

                    $this->normalizedUrls[] = $normalized;
                }

                $this->normalizedUrls = array_values(array_unique($this->normalizedUrls));

                if (! $this->isMethod('post') && $this->normalizedUrls !== []) {
                    $exists = MonitoredPage::query()->where('domain_id', $domain->id)
                        ->where('url_hash', MonitoredPage::hashUrl($this->normalizedUrls[0]))
                        ->whereKeyNot($this->route('page')->id)->exists();

                    if ($exists) {
                        $validator->errors()->add('url', 'Deze pagina wordt al gemonitord.');
                    }
                }

                if ($this->filled('expected_final_url') && UrlNormalizer::normalize((string) $this->input('expected_final_url'), keepQuery: true) === null) {
                    $validator->errors()->add('expected_final_url', 'Ongeldige verwachte eind-URL.');
                }

                foreach ([$this->input('content_selector'), ...(array) $this->input('content_exclusions')] as $selector) {
                    if (is_string($selector) && $selector !== '' && ! ContentFingerprint::isValidSelector($selector)) {
                        $validator->errors()->add('content_selector', "Selector \"{$selector}\" wordt niet ondersteund (gebruik tag, #id, .class, tag.class of [attribuut=waarde]).");
                    }
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'urls' => 'URL\'s',
            'url' => 'URL',
            'check_interval_minutes' => 'controle-interval',
            'timeout_seconds' => 'time-out',
            'failure_threshold' => 'faaldrempel',
            'recovery_threshold' => 'hersteldrempel',
            'expected_status_codes' => 'verwachte statuscodes',
            'max_response_ms' => 'maximale responstijd',
            'expected_final_url' => 'verwachte eind-URL',
            'header_assertions' => 'headercontroles',
            'header_assertions.*.name' => 'headernaam',
            'monitored_headers' => 'gevolgde headers',
            'monitored_headers.*' => 'gevolgde header',
            'alert_severity' => 'ernst',
            'sla_target' => 'beschikbaarheidsdoel',
        ];
    }

    /** Settings for the model (without the URL). */
    public function pageData(): array
    {
        return collect($this->validated())->except(['urls', 'url'])->all();
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, array{name: string, mode: string, value: ?string}>
     */
    public static function parseHeaderAssertions(array $lines): array
    {
        $assertions = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '!')) {
                $assertions[] = ['name' => trim(substr($line, 1)), 'mode' => 'absent', 'value' => null];
            } elseif (preg_match('/^([^=~]+?)\s*([=~])\s*(.*)$/', $line, $m)) {
                $assertions[] = ['name' => trim($m[1]), 'mode' => $m[2] === '=' ? 'equals' : 'contains', 'value' => trim($m[3])];
            } else {
                $assertions[] = ['name' => $line, 'mode' => 'present', 'value' => null];
            }
        }

        return $assertions;
    }

    /** Text form of stored header assertions for the edit form. */
    public static function headerAssertionsText(?array $assertions): string
    {
        return collect($assertions ?? [])->map(fn ($a) => match ($a['mode'] ?? 'present') {
            'absent' => '!'.$a['name'],
            'equals' => $a['name'].' = '.($a['value'] ?? ''),
            'contains' => $a['name'].' ~ '.($a['value'] ?? ''),
            default => $a['name'],
        })->implode("\n");
    }
}
