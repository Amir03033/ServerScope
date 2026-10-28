<?php

namespace App\Http\Controllers;

use App\Services\ServerRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServerRegistrationController extends Controller
{
    public function store(
        Request $request,
        ServerRegistrationService $registrationService,
    ): JsonResponse {
        abort_unless(
            \Illuminate\Support\Facades\Gate::allows('admin'),
            403,
            'Alleen beheerders mogen servers registreren.',
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'hostname' => ['required', 'string', 'max:255'],
            'ip_address' => [
                'nullable',
                'ip',
            ],
            'operating_system' => ['nullable', 'string', 'max:100'],
            'credential_name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],
        ]);

        $credentialName = $validated['credential_name']
            ?? 'Primary agent';

        unset($validated['credential_name']);

        $result = $registrationService->register(
            $validated,
            $credentialName,
        );

        return response()->json([
            'message' => 'Server succesvol geregistreerd.',
            'server' => [
                'id' => $result['server']->id,
                'name' => $result['server']->name,
                'hostname' => $result['server']->hostname,
                'status' => $result['server']->status,
            ],
            'agent_token' => $result['token'],
        ], 201);
    }
}
