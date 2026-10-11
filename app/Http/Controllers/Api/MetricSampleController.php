<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MetricSample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class MetricSampleController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $server = $request->attributes->get('agentServer');

        abort_unless($server !== null, 401);

        $validated = $request->validate([
            'collected_at' => [
                'required',
                'date',
                'after_or_equal:' . now()->subMinutes(10)->toIso8601String(),
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
            $validated['collected_at']
        );

        $sample = DB::transaction(function () use (
            $server,
            $validated,
            $collectedAt,
        ): MetricSample {
            $duplicate = MetricSample::query()
                ->where('server_id', $server->id)
                ->where('collected_at', $collectedAt)
                ->exists();

            if ($duplicate) {
                abort(409, 'Voor dit tijdstip bestaat al een meting.');
            }

            $sampleData = $validated;
            unset($sampleData['collected_at']);

            $sample = $server->metricSamples()->create([
                ...$sampleData,
                'collected_at' => $collectedAt,
            ]);

            $server->forceFill([
                'last_seen_at' => now(),
                'status' => 'online',
                'agent_version' => $validated['agent_version']
                    ?? $server->agent_version,
            ])->save();

            return $sample;
        });

        return response()->json([
            'message' => 'Meting opgeslagen.',
            'metric_sample_id' => $sample->id,
            'collected_at' => $sample->collected_at->toIso8601String(),
        ], Response::HTTP_CREATED);
    }
}
