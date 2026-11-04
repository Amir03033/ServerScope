<?php

namespace App\Jobs;

use App\Services\SelfPings\SelfPings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Proves a queue worker runs: it only calls the ping URL once a worker picks it up. */
class PingFromQueue implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public string $url, public ?int $queuedAt = null)
    {
        $this->queuedAt ??= now()->getTimestamp();
    }

    public function handle(): void
    {
        // A backlog of old checks says nothing about now: a worker that catches up only reports the fresh ones.
        if (now()->getTimestamp() - $this->queuedAt > 180) {
            return;
        }

        SelfPings::success($this->url);
    }
}
