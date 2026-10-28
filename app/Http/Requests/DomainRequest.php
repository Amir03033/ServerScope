<?php

namespace App\Http\Requests;

use App\Models\Domain;
use App\Tenancy\TeamScope;
use App\Tenancy\Tenant;
use App\Services\Domains\DomainNormalizer;
use App\Support\Time;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('admin');
    }

    protected function prepareForValidation(): void
    {
        $normalized = is_string($this->name) ? app(DomainNormalizer::class)->normalize($this->name) : null;

        $path = trim((string) ($this->path ?? '/'));

        $this->merge([
            'name_input' => $this->name,
            'name' => $normalized ?? $this->name,
            'name_valid' => $normalized !== null,
            'path' => $path === '' ? '/' : (str_starts_with($path, '/') ? $path : '/'.$path),
            'expected_content' => $this->filled('expected_content') ? (string) $this->expected_content : null,
            'expected_status_codes' => $this->filled('expected_status_codes') ? preg_replace('/\s+/', '', (string) $this->expected_status_codes) : null,
            'ssl_warning_days' => $this->parseDays($this->ssl_warning_days),
            'expiry_warning_days' => $this->parseDays($this->expiry_warning_days),
            'notes' => $this->filled('notes') ? trim((string) $this->notes) : null,
            'sla_target' => $this->filled('sla_target') ? str_replace(',', '.', (string) $this->sla_target) : null,
            'notifications_enabled' => $this->has('notifications_enabled') ? $this->boolean('notifications_enabled') : true,
            ...collect(['follow_redirects', 'http_enabled', 'ssl_enabled', 'dns_enabled', 'expiry_enabled'])
                ->mapWithKeys(fn ($key) => [$key => $this->boolean($key)])->all(),
        ]);
    }

    public function rules(): array
    {
        $min = (int) config('serverscope.monitoring.min_interval_minutes', 1);

        $rules = [
            'name' => ['required', 'string', 'max:253'],
            'scheme' => ['required', 'in:http,https'],
            'path' => ['required', 'string', 'max:255', 'regex:/^\/[^\s#]*$/'],
            'check_interval_minutes' => ['required', 'integer', "between:{$min},1440"],
            'timeout_seconds' => ['required', 'integer', 'between:2,30'],
            'failure_threshold' => ['required', 'integer', 'between:1,10'],
            'recovery_threshold' => ['required', 'integer', 'between:1,10'],
            'expected_status_codes' => ['nullable', 'string', 'max:100', 'regex:/^\d{3}(-\d{3})?(,\d{3}(-\d{3})?)*$/'],
            'expected_content' => ['nullable', 'string', 'max:255'],
            'follow_redirects' => ['boolean'],
            'http_enabled' => ['boolean'],
            'ssl_enabled' => ['boolean'],
            'dns_enabled' => ['boolean'],
            'expiry_enabled' => ['boolean'],
            'ssl_warning_days' => ['array', 'min:1', 'max:10'],
            'ssl_warning_days.*' => ['integer', 'between:1,365'],
            'expiry_warning_days' => ['array', 'min:1', 'max:10'],
            'expiry_warning_days.*' => ['integer', 'between:1,365'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'notifications_enabled' => ['boolean'],
            'sla_target' => ['nullable', 'numeric', 'between:50,100'],
            'maintenance_starts_at' => ['nullable', 'date', 'required_with:maintenance_ends_at'],
            'maintenance_ends_at' => ['nullable', 'date', 'after:maintenance_starts_at'],
            'maintenance_reason' => ['nullable', 'string', 'max:255'],
        ];

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('name_valid')) {
                    $validator->errors()->add('name', 'Voer een geldige publieke domeinnaam in, bijvoorbeeld example.com of www.example.nl.');

                    return;
                }

                // Domain names are unique across the whole platform (unique
                // index); look in every team, but only explain the conflict
                // when the domain belongs to the own team.
                $existing = Domain::query()->withoutGlobalScope(TeamScope::class)->withTrashed()
                    ->where('name', $this->input('name'))
                    ->when($this->route('domain'), fn ($q, $domain) => $q->whereKeyNot($domain->id))
                    ->first();

                if ($existing !== null && $existing->team_id !== Tenant::id()) {
                    $validator->errors()->add('name', 'Dit domein kan niet worden toegevoegd. Neem contact op met de beheerder van ServerScope als je denkt dat dit niet klopt.');
                } elseif ($existing?->trashed()) {
                    $validator->errors()->add('name', 'Dit domein is eerder verwijderd. Herstel het via het filter "Verwijderd".');
                } elseif ($existing) {
                    $validator->errors()->add('name', 'Dit domein wordt al gemonitord.');
                }

                // The limit applies per team.
                if ($this->isMethod('post') && Domain::withTrashed()->where('team_id', Tenant::id())->count() >= (int) config('serverscope.monitoring.max_domains', 1000)) {
                    $validator->errors()->add('name', 'Het maximale aantal domeinen is bereikt.');
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'domeinnaam',
            'path' => 'pad',
            'check_interval_minutes' => 'controle-interval',
            'timeout_seconds' => 'time-out',
            'failure_threshold' => 'faaldrempel',
            'recovery_threshold' => 'hersteldrempel',
            'expected_status_codes' => 'verwachte statuscodes',
            'expected_content' => 'verwachte inhoud',
            'ssl_warning_days' => 'certificaatwaarschuwingen',
            'ssl_warning_days.*' => 'certificaatwaarschuwing',
            'expiry_warning_days' => 'vervalwaarschuwingen',
            'expiry_warning_days.*' => 'vervalwaarschuwing',
            'maintenance_ends_at' => 'einde onderhoud',
            'maintenance_starts_at' => 'start onderhoud',
            'sla_target' => 'beschikbaarheidsdoel',
        ];
    }

    /**
     * Validated data ready for the model: TLS checks only apply to https.
     */
    public function domainData(): array
    {
        $data = collect($this->validated())->except(['maintenance_starts_at', 'maintenance_ends_at'])->all();

        if ($data['scheme'] === 'http') {
            $data['ssl_enabled'] = false;
        }

        $data['ssl_warning_days'] = Domain::normaliseDays($data['ssl_warning_days']);
        $data['expiry_warning_days'] = Domain::normaliseDays($data['expiry_warning_days']);
        $data['maintenance_starts_at'] = Time::parse($this->validated('maintenance_starts_at'));
        $data['maintenance_ends_at'] = Time::parse($this->validated('maintenance_ends_at'));

        if ($data['maintenance_starts_at'] === null) {
            $data['maintenance_reason'] = null;
        }

        return $data;
    }

    private function parseDays(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        $parts = array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== '');

        return array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $parts));
    }
}
