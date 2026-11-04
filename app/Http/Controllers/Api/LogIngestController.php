<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LogEntry;
use App\Services\Logs\LogFingerprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * POST /api/agent/logs: error and warning lines from the log files an
 * agent follows. Only its own server; text is redacted once more here.
 * At most MAX_ENTRIES per request and MAX_PER_HOUR per server, so a log
 * that floods cannot fill the database; the overflow is only counted.
 */
class LogIngestController extends Controller
{
    public const MAX_ENTRIES = 200;

    public const MAX_PER_HOUR = 5000;

    public function __invoke(Request $request): JsonResponse
    {
        $server = $request->attributes->get('agentServer');
        abort_unless($server !== null, 401);

        $v = $request->validate([
            'entries' => ['present', 'array', 'max:'.self::MAX_ENTRIES],
            'entries.*.source' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
            'entries.*.level' => ['required', Rule::in(LogEntry::LEVELS)],
            'entries.*.message' => ['required', 'string', 'max:4000'],
            'entries.*.context' => ['nullable', 'string', 'max:16000'],
            'entries.*.occurred_at' => ['required', 'date', 'after_or_equal:'.now()->subHour()->toIso8601String(), 'before_or_equal:'.now()->addMinutes(2)->toIso8601String()],
            'dropped' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        $room = max(0, self::MAX_PER_HOUR - LogEntry::query()->where('server_id', $server->id)->where('created_at', '>=', now()->subHour())->count());
        $accepted = array_slice($v['entries'], 0, $room);
        $now = now();

        $rows = array_map(function (array $entry) use ($server, $now) {
            $message = LogFingerprint::redact(trim($entry['message']));

            return [
                'team_id' => $server->team_id,
                'server_id' => $server->id,
                'source' => $entry['source'],
                'level' => $entry['level'],
                'message' => mb_substr($message, 0, 1000),
                'context' => isset($entry['context']) && trim($entry['context']) !== '' ? mb_substr(LogFingerprint::redact($entry['context']), 0, 8000) : null,
                'fingerprint' => LogFingerprint::of($entry['source'], $message),
                'occurred_at' => Carbon::parse($entry['occurred_at'])->utc(),
                'created_at' => $now,
            ];
        }, $accepted);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('log_entries')->insert($chunk);
        }

        return response()->json([
            'accepted' => count($rows),
            'skipped' => count($v['entries']) - count($rows),
        ], 201);
    }
}
