<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HelpNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HelpNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        $notifications = HelpNotification::query()
            ->where('user_id', $user->id)
            ->with(['ticket:id,ticket_number,subject,status'])
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn (HelpNotification $notification) => $this->serialize($notification));

        $unreadCount = HelpNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        $unreadCount = HelpNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        $latest = HelpNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (HelpNotification $notification) => $this->serialize($notification));

        return response()->json([
            'unread_count' => $unreadCount,
            'latest' => $latest,
        ]);
    }

    public function markRead(Request $request, string $notificationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        $notification = HelpNotification::query()
            ->where('user_id', $user->id)
            ->where('id', $notificationId)
            ->first();

        if (! $notification) {
            return response()->json(['detail' => 'Notification not found.', 'code' => 'not_found'], 404);
        }

        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return response()->json([
            'status' => 'read',
            'notification' => $this->serialize($notification),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        $ticketId = trim((string) $request->input('ticket_id', ''));

        $query = HelpNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at');

        if ($ticketId !== '') {
            $query->where('help_ticket_id', $ticketId);
        }

        $updated = $query->update(['read_at' => now()]);

        return response()->json([
            'status' => 'read',
            'updated' => $updated,
        ]);
    }

    private function serialize(HelpNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'help_ticket_id' => $notification->help_ticket_id,
            'ticket_number' => $notification->ticket?->ticket_number,
            'ticket_subject' => $notification->ticket?->subject,
            'ticket_status' => $notification->ticket?->status,
            'read_at' => optional($notification->read_at)?->toIso8601String(),
            'created_at' => optional($notification->created_at)?->toIso8601String(),
            'is_unread' => $notification->read_at === null,
        ];
    }
}
