<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $servers = Server::query()
            ->with('latestMetricSample')
            ->orderBy('name')
            ->get()
            ->map(function (Server $server): Server {
                $server->display_status = match (true) {
                    $server->last_seen_at === null => 'pending',
                    $server->last_seen_at->lt(now()->subMinutes(2)) => 'offline',
                    default => 'online',
                };

                return $server;
            });

        return view('dashboard', [
            'servers' => $servers,
            'serverCount' => $servers->count(),
            'onlineCount' => $servers->where('display_status', 'online')->count(),
            'offlineCount' => $servers->where('display_status', 'offline')->count(),
            'pendingCount' => $servers->where('display_status', 'pending')->count(),
        ]);
    }
}
