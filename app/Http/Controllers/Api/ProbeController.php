<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Probe;
use App\Models\ProbeCheck;
use App\Services\Probes\ProbeConfirmation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The probe's side of Global Pulse Lite (signed, see AuthenticateProbe).
 * A job carries only what an availability check needs: the website URL,
 * time-out and redirect setting; no expected content, headers or secrets.
 * Results are only accepted for this probe's own, still pending jobs.
 */
class ProbeController extends Controller
{
    public const MAX_JOBS = 20;

    public function jobs(Request $request): JsonResponse
    {
        /** @var Probe $probe */
        $probe = $request->attributes->get('probe');
        $jobs = ProbeCheck::query()->withoutGlobalScopes()->where('probe_id', $probe->id)->where('status', 'pending')
            ->where('expires_at', '>', now())->orderBy('requested_at')->limit(self::MAX_JOBS)->get();
        $domains = Domain::query()->withoutGlobalScopes()->whereIn('id', $jobs->pluck('domain_id'))->get()->keyBy('id');

        $data = [];

        foreach ($jobs as $job) {
            $domain = $domains->get($job->domain_id);

            if ($domain === null) {
                continue;
            }

            $job->forceFill(['claimed_at' => $job->claimed_at ?? now()])->save();
            $data[] = [
                'id' => $job->id,
                'url' => $domain->url(),
                'timeout_seconds' => min((float) $domain->timeout_seconds, (float) config('serverscope.probe.max_timeout_seconds', 10)),
                'follow_redirects' => (bool) $domain->follow_redirects,
                'expires_at' => $job->expires_at->toIso8601String(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function results(Request $request, ProbeConfirmation $confirmation): JsonResponse
    {
        /** @var Probe $probe */
        $probe = $request->attributes->get('probe');
        $v = $request->validate([
            'results' => ['required', 'array', 'min:1', 'max:'.self::MAX_JOBS],
            'results.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'results.*.checked_at' => ['required', 'date'],
            'results.*.http_status_code' => ['nullable', 'integer', 'between:100,599'],
            'results.*.response_time_ms' => ['nullable', 'numeric', 'between:0,600000'],
            'results.*.error_code' => ['nullable', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
            'results.*.message' => ['nullable', 'string', 'max:200'],
        ]);

        $accepted = [];
        $rejected = [];

        foreach ($v['results'] as $result) {
            $job = ProbeCheck::query()->withoutGlobalScopes()->whereKey($result['id'])->where('probe_id', $probe->id)->first();
            $checkedAt = Carbon::parse($result['checked_at'])->utc();

            // Only this probe's pending, unexpired jobs, measured after the
            // request and not in the future.
            if ($job === null || $job->status !== 'pending' || $job->expires_at->lt(now())
                || $checkedAt->lt($job->requested_at->copy()->subSeconds(5)) || $checkedAt->gt(now()->addSeconds(30))
                || ($result['http_status_code'] ?? null) === null && ($result['error_code'] ?? null) === null) {
                $rejected[] = (int) $result['id'];

                continue;
            }

            $confirmation->record($job, [
                'checked_at' => $checkedAt,
                'http_status_code' => $result['http_status_code'] ?? null,
                'response_time_ms' => isset($result['response_time_ms']) ? round((float) $result['response_time_ms'], 1) : null,
                'error_code' => $result['error_code'] ?? null,
                'message' => isset($result['message']) ? mb_substr($result['message'], 0, 200) : null,
            ]);
            $accepted[] = $job->id;
        }

        return response()->json(['accepted' => $accepted, 'rejected' => $rejected], $accepted === [] ? 422 : 200);
    }
}
