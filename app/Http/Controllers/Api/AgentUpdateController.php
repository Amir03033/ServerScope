<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentUpdateRequest;
use App\Releases\ReleaseManifest;
use App\Services\AgentUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The updater (serverscope-updater, root on the customer server). The
 * server always comes from the credential; a request of another server
 * does not exist (404). The answer holds only a request id and a version
 * number: the updater builds the download URLs itself and installs only
 * what is signed with its locally stored key.
 */
class AgentUpdateController extends Controller
{
    private const VERSION = 'regex:/\A'.ReleaseManifest::VERSION_PATTERN.'\z/';

    public function check(Request $request, AgentUpdates $updates): JsonResponse
    {
        $this->refuseUnknown($request->query(), ['installed', 'updater', 'state']);

        $checkIn = $request->validate([
            'installed' => ['required', 'string', 'max:20', self::VERSION],
            'updater' => ['required', 'string', 'regex:/\A[1-9]\d{0,2}\z/'],
            'state' => ['required', 'string', Rule::in(['enabled', 'disabled'])],
        ]);
        $checkIn['updater'] = (int) $checkIn['updater'];

        $offer = $updates->checkIn($request->attributes->get('agentServer'), $checkIn);

        return response()->json(['data' => ['update' => $offer ? [
            'request_id' => $offer->id,
            'version' => $offer->target_version,
        ] : null]]);
    }

    public function report(Request $request, AgentUpdates $updates): JsonResponse
    {
        $this->refuseUnknown($request->all(), ['request_id', 'status', 'reason', 'installed']);

        $report = $request->validate([
            'request_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', Rule::in(array_merge(['in_progress'], AgentUpdates::TERMINAL))],
            'reason' => ['nullable', 'string', Rule::in(array_keys(AgentUpdateRequest::REASONS))],
            'installed' => ['nullable', 'string', 'max:20', self::VERSION],
        ]);

        $server = $request->attributes->get('agentServer');
        $updateRequest = AgentUpdateRequest::query()->where('server_id', $server->id)->find((int) $report['request_id']);

        if ($updateRequest === null) {
            return response()->json(['message' => 'Onbekend updateverzoek.'], 404);
        }

        if (! $updates->report($updateRequest, $report)) {
            return response()->json(['message' => 'Dit updateverzoek heeft al een andere status.', 'status' => $updateRequest->status], 409);
        }

        return response()->json(['data' => ['status' => $updateRequest->fresh()->status]]);
    }

    /** @param  array<string, mixed>  $input */
    private function refuseUnknown(array $input, array $allowed): void
    {
        $unknown = array_diff(array_keys($input), $allowed);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['input' => 'Onbekende velden: '.implode(', ', array_map(fn ($key) => substr((string) preg_replace('/[^a-z_]/', '?', (string) $key), 0, 30), $unknown))]);
        }
    }
}
