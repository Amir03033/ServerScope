<?php

namespace App\Http\Controllers;

use App\Models\NotificationLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $request->validate(['tab' => ['nullable', Rule::in(['inbox', 'deliveries'])]])['tab'] ?? 'inbox';
        $user = $request->user();

        $notifications = $tab === 'inbox'
            ? $user->notifications()->latest()->paginate(20)->withQueryString()
            : null;

        // Administrators see all deliveries, others only their own.
        $deliveries = $tab === 'deliveries'
            ? NotificationLog::query()
                ->with(['user:id,name', 'incident:id,title'])
                ->when(! $user->can('admin'), fn ($q) => $q->where('user_id', $user->id))
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
            : null;

        return view('notifications.index', compact('tab', 'notifications', 'deliveries'));
    }

    public function read(Request $request, string $id): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        // Only follow relative in-app paths.
        return is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? redirect($url)
            : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'Alle meldingen zijn als gelezen gemarkeerd.');
    }
}
