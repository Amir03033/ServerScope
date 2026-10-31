<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetricSample;
use App\Services\AlertDetectionService;
use App\Services\ServiceMonitor;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MetricSampleController extends Controller
{
    /**
     * Oldest accepted sample. Hourly rollups treat an hour as closed one
     * minute after this window (App\Services\Analytics\MetricRollups).
     */
    public const MAX_SAMPLE_AGE_MINUTES = 10;

    public function store(
        Request $request,
        AlertDetectionService $alertDetectionService,
        ServiceMonitor $serviceMonitor,
    ): JsonResponse {
        $server = $request->attributes->get('agentServer');

        abort_unless($server !== null, 401);

        $validated = $request->validate([
            'collected_at' => [
                'required',
                'date',
                'after_or_equal:' . now()->subMinutes(self::MAX_SAMPLE_AGE_MINUTES)->toIso8601String(),
                'before_or_equal:' . now()->addSeconds(30)->toIso8601String(),
            ],

            'cpu_percent' => ['nullable', 'numeric', 'between:0,100'],

            'load_1' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'load_5' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'load_15' => ['nullable', 'numeric', 'min:0', 'max:100000'],

            'memory_total_bytes' => ['nullable', 'integer', 'min:0'],
            'memory_used_bytes' => ['nullable', 'integer', 'min:0'],
            'memory_available_bytes' => ['nullable', 'integer', 'min:0'],

            'swap_total_bytes' => ['nullable', 'integer', 'min:0'],
            'swap_used_bytes' => ['nullable', 'integer', 'min:0'],

            'disks' => ['nullable', 'array', 'max:20'],
            'disks.*.path' => ['required', 'string', 'max:255'],
            'disks.*.total_bytes' => ['required', 'integer', 'min:0'],
            'disks.*.used_bytes' => ['required', 'integer', 'min:0'],
            'disks.*.available_bytes' => ['required', 'integer', 'min:0'],
            'disks.*.percent' => ['required', 'numeric', 'between:0,100'],

            'network_rx_bytes' => ['nullable', 'integer', 'min:0'],
            'network_tx_bytes' => ['nullable', 'integer', 'min:0'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'agent_version' => ['nullable', 'string', 'max:50'],

            // Added in agent 0.2.0; older agents omit them.
            'disk_read_bytes' => ['nullable', 'integer', 'min:0'],
            'disk_write_bytes' => ['nullable', 'integer', 'min:0'],
            'network_errors_in' => ['nullable', 'integer', 'min:0'],
            'network_errors_out' => ['nullable', 'integer', 'min:0'],
            'network_drops_in' => ['nullable', 'integer', 'min:0'],
            'network_drops_out' => ['nullable', 'integer', 'min:0'],
            'process_count' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'agent_checksum' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
            // Server metadata (logical CPUs), stored on the server, not per sample.
            'cpu_count' => ['nullable', 'integer', 'between:1,4096'],
            'services' => ['nullable', 'array', 'max:'.ServiceMonitor::MAX_SERVICES],
            'services.*.name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9@._:-]+$/', 'distinct'],
            'services.*.state' => ['required', 'string', Rule::in(['running', 'starting', 'stopped', 'failed', 'not_found', 'unknown'])],
            'services.*.detail' => ['nullable', 'string', 'max:100'],
        ]);

        foreach ([
                     'memory_used_bytes' => 'memory_total_bytes',
                     'memory_available_bytes' => 'memory_total_bytes',
                     'swap_used_bytes' => 'swap_total_bytes',
                 ] as $valueField => $totalField) {
            if (
                isset($validated[$valueField], $validated[$totalField])
                && $validated[$valueField] > $validated[$totalField]
            ) {
                throw ValidationException::withMessages([
                    $valueField => 'De waarde mag niet groter zijn dan het totaal.',
                ]);
            }
        }

        foreach ($validated['disks'] ?? [] as $index => $disk) {
            if (
                $disk['used_bytes'] > $disk['total_bytes']
                || $disk['available_bytes'] > $disk['total_bytes']
                || $disk['used_bytes'] + $disk['available_bytes'] > $disk['total_bytes']
            ) {
                throw ValidationException::withMessages([
                    "disks.$index" => 'De schijfwaarden zijn inconsistent.',
                ]);
            }
        }

        $collectedAt = \Illuminate\Support\Carbon::parse(
            $validated['collected_at'],
        );

        $sampleData = $validated;
        $services = $validated['services'] ?? null;

        unset($sampleData['collected_at'], $sampleData['services'], $sampleData['agent_checksum'], $sampleData['cpu_count']);

        try {
            $sample = DB::transaction(function () use (
                $server,
                $sampleData,
                $collectedAt,
                $validated,
                $alertDetectionService,
                $services,
                $serviceMonitor,
            ): MetricSample {
                $sample = $server->metricSamples()->create([
                    ...$sampleData,
                    'collected_at' => $collectedAt,
                ]);

                $server->forceFill([
                    'last_seen_at' => now(),
                    'status' => 'online',
                    'agent_version' => $validated['agent_version']
                        ?? $server->agent_version,
                    'agent_checksum' => $validated['agent_checksum']
                        ?? $server->agent_checksum,
                    'cpu_count' => $validated['cpu_count']
                        ?? $server->cpu_count,
                ])->save();

                // Only agents configured to watch services send the key.
                if ($services !== null) {
                    $serviceMonitor->record($server, $services, now());
                }

                // Evaluate alerts as part of the same database transaction.
                $alertDetectionService->evaluate($sample);

                return $sample;
            });
        } catch (QueryException $exception) {
            // Only convert duplicate sample timestamps to HTTP 409.
            // Other database errors must still be reported normally.
            $isUniqueViolation = in_array(
                (string) $exception->getCode(),
                ['23000', '23505'],
                true,
            );

            if ($isUniqueViolation) {
                $duplicateExists = MetricSample::query()
                    ->where('server_id', $server->id)
                    ->where('collected_at', $collectedAt)
                    ->exists();

                if ($duplicateExists) {
                    return response()->json([
                        'message' => 'Voor dit tijdstip bestaat al een meting.',
                    ], Response::HTTP_CONFLICT);
                }
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Meting opgeslagen.',
            'metric_sample_id' => $sample->id,
            'collected_at' => $sample->collected_at->toIso8601String(),
        ], Response::HTTP_CREATED);
    }
}
