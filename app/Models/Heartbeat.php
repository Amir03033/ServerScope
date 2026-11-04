<?php

namespace App\Models;

use App\Tenancy\BelongsToTeam;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * A scheduled job (backup, import, schedule:run…) that reports in on its
 * own ping URL. The token in that URL is the only credential, so it is
 * long and random. A job that has never pinged is "new" and never alerts;
 * after the first ping it is expected again by the schedule plus a grace
 * period, otherwise it is "down" and an incident is opened.
 */
class Heartbeat extends Model
{
    use BelongsToTeam;

    public const STATUSES = ['new', 'up', 'down', 'paused'];

    public const SCHEDULE_INTERVAL = 'interval';

    public const SCHEDULE_CRON = 'cron';

    public const PERIODS = [1, 5, 10, 15, 30, 60, 120, 360, 720, 1440, 10080];

    public const MAX_PER_TEAM = 200;

    protected $fillable = ['server_id', 'name', 'schedule_type', 'period_minutes', 'cron_expression', 'timezone', 'grace_minutes', 'severity'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'last_ping_at' => 'datetime',
            'last_start_at' => 'datetime',
            'next_due_at' => 'datetime',
            'paused_at' => 'datetime',
            'period_minutes' => 'integer',
            'grace_minutes' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function pings(): HasMany
    {
        return $this->hasMany(HeartbeatPing::class);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function pingUrl(string $suffix = ''): string
    {
        return route('heartbeats.ping', ['token' => $this->token]).($suffix !== '' ? '/'.$suffix : '');
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function fingerprint(): string
    {
        return 'heartbeat:'.$this->id;
    }

    /**
     * When the next ping is expected after a run at $after (without the
     * grace period). Null when the schedule cannot be read.
     */
    public function dueAfter(CarbonInterface $after): ?CarbonImmutable
    {
        $after = CarbonImmutable::instance($after);

        if ($this->schedule_type === self::SCHEDULE_CRON) {
            try {
                $next = (new CronExpression((string) $this->cron_expression))->getNextRunDate($after->toDateTime(), 0, false, $this->timezone ?: 'UTC');
            } catch (Throwable) {
                return null;
            }

            return CarbonImmutable::instance($next)->utc();
        }

        return $this->period_minutes ? $after->addMinutes($this->period_minutes) : null;
    }

    /** The moment the job counts as late: the expected time plus the grace period. */
    public function lateAt(): ?CarbonImmutable
    {
        return $this->next_due_at ? CarbonImmutable::instance($this->next_due_at)->addMinutes($this->grace_minutes) : null;
    }

    public function scheduleLabel(): string
    {
        if ($this->schedule_type === self::SCHEDULE_CRON) {
            return __('heartbeats.schedule.cron_label', ['expression' => $this->cron_expression, 'timezone' => $this->timezone]);
        }

        return self::periodLabel((int) $this->period_minutes);
    }

    public static function periodLabel(int $minutes): string
    {
        return match (true) {
            $minutes % 10080 === 0 => trans_choice('heartbeats.schedule.weeks', intdiv($minutes, 10080)),
            $minutes % 1440 === 0 => trans_choice('heartbeats.schedule.days', intdiv($minutes, 1440)),
            $minutes % 60 === 0 => trans_choice('heartbeats.schedule.hours', intdiv($minutes, 60)),
            default => trans_choice('heartbeats.schedule.minutes', $minutes),
        };
    }

    public static function validCron(?string $expression): bool
    {
        return $expression !== null && trim($expression) !== '' && CronExpression::isValidExpression(trim($expression));
    }
}
