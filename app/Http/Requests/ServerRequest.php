<?php

namespace App\Http\Requests;

use App\Models\Server;
use App\Rules\Hostname;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('admin');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->name) ? trim($this->name) : $this->name,
            'hostname' => is_string($this->hostname) ? strtolower(rtrim(trim($this->hostname), '.')) : $this->hostname,
            'ip_address' => $this->filled('ip_address') ? trim((string) $this->ip_address) : null,
            'operating_system' => $this->filled('operating_system') ? trim((string) $this->operating_system) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->notes) : null,
        ]);
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'hostname' => ['required', 'string', 'max:255', new Hostname],
            'ip_address' => ['nullable', 'ip'],
            'operating_system' => ['nullable', 'string', 'max:100'],
            'environment' => ['required', Rule::in(array_keys(Server::ENVIRONMENTS))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($this->isMethod('post')) {
            $rules['credential_name'] = ['required', 'string', 'max:100'];
        } else {
            $rules['maintenance_starts_at'] = ['nullable', 'date', 'required_with:maintenance_ends_at'];
            $rules['maintenance_ends_at'] = ['nullable', 'date', 'after:maintenance_starts_at'];
            $rules['maintenance_reason'] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'name' => 'servernaam',
            'hostname' => 'hostname',
            'ip_address' => 'IP-adres',
            'operating_system' => 'besturingssysteem',
            'environment' => 'omgeving',
            'credential_name' => 'naam van de agentcredential',
            'maintenance_starts_at' => 'start onderhoud',
            'maintenance_ends_at' => 'einde onderhoud',
        ];
    }
}
