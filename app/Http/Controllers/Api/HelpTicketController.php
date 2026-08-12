<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HelpTicket;
use App\Models\HelpTicketReply;
use App\Models\User;
use App\Services\HelpNotificationService;
use App\Services\SystemConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HelpTicketController extends Controller
{
    private const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    private const ACTIVE_STATUSES = ['open', 'answered'];

    private const ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
        'text/plain',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function __construct(
        private readonly HelpNotificationService $notifications,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        if (! $this->systemConfig->isHelpTicketsEnabled()) {
            return response()->json([
                'detail' => 'Help tickets are currently disabled by application settings.',
                'code' => 'help_tickets_disabled',
            ], 403);
        }

        if ($this->activeTicketFor($user->id)) {
            return response()->json([
                'detail' => 'You already have an active help ticket. Close it before opening a new one.',
                'code' => 'active_ticket_exists',
            ], 422);
        }

        $payload = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            'attachment' => ['nullable', 'file', 'max:5120'],
        ]);

        $attachmentMeta = [
            'attachment_path' => null,
            'attachment_original_name' => null,
            'attachment_mime' => null,
            'attachment_size' => null,
        ];

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
                return response()->json([
                    'detail' => 'Attachment must be 5 MB or smaller.',
                    'code' => 'attachment_too_large',
                ], 422);
            }

            $mime = (string) ($file->getMimeType() ?: '');
            if ($mime !== '' && ! in_array($mime, self::ALLOWED_MIME, true)) {
                return response()->json([
                    'detail' => 'This file type is not allowed. Use an image, PDF, text, or Word document.',
                    'code' => 'attachment_type_invalid',
                ], 422);
            }

            $storedName = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('help-attachments', $storedName, 'local');
            $attachmentMeta = [
                'attachment_path' => $path,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime' => $mime ?: null,
                'attachment_size' => $file->getSize(),
            ];
        }

        $ticket = DB::transaction(function () use ($user, $payload, $attachmentMeta) {
            if ($this->activeTicketFor($user->id)) {
                return null;
            }

            return HelpTicket::query()->create([
                'ticket_number' => $this->nextTicketNumber(),
                'user_id' => $user->id,
                'email' => strtolower(trim($payload['email'])),
                'subject' => trim($payload['subject']),
                'message' => trim($payload['message']),
                'status' => 'open',
                'closed_at' => null,
                ...$attachmentMeta,
            ]);
        });

        if (! $ticket) {
            return response()->json([
                'detail' => 'You already have an active help ticket. Close it before opening a new one.',
                'code' => 'active_ticket_exists',
            ], 422);
        }

        $this->notifications->ticketCreated($ticket, $user);

        return response()->json([
            'status' => 'created',
            'ticket' => $this->serializeTicket($ticket),
        ], 201);
    }

    public function myTickets(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        $tickets = HelpTicket::query()
            ->where('user_id', $user->id)
            ->with(['replies.admin:id,email,display_name,role', 'replies.user:id,email,display_name,role'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (HelpTicket $ticket) => $this->serializeTicket($ticket, true));

        return response()->json([
            'tickets' => $tickets,
            'has_active_ticket' => $tickets->contains(fn (array $ticket) => in_array($ticket['status'], self::ACTIVE_STATUSES, true)),
        ]);
    }

    public function userReply(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');

        $payload = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = $this->findOwnedTicket($ticketId, $user);
        if (! $ticket) {
            return response()->json(['detail' => 'Ticket not found.', 'code' => 'not_found'], 404);
        }

        if ($ticket->isClosed()) {
            return response()->json([
                'detail' => 'This ticket is closed. You cannot reply to it.',
                'code' => 'ticket_closed',
            ], 422);
        }

        HelpTicketReply::query()->create([
            'help_ticket_id' => $ticket->id,
            'admin_id' => null,
            'user_id' => $user->id,
            'author_role' => 'user',
            'message' => trim($payload['message']),
        ]);

        $ticket->status = 'open';
        $ticket->closed_at = null;
        $ticket->save();

        $this->notifications->userReplied($ticket, $user);

        $ticket->load(['replies.admin:id,email,display_name,role', 'replies.user:id,email,display_name,role']);

        return response()->json([
            'status' => 'replied',
            'ticket' => $this->serializeTicket($ticket, true),
        ]);
    }

    public function close(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('admin');
        $isAdmin = in_array($actor->role, ['admin', 'super_admin', 'sub_admin'], true);

        $ticket = $this->findTicket($ticketId);
        if (! $ticket) {
            return response()->json(['detail' => 'Ticket not found.', 'code' => 'not_found'], 404);
        }

        if (! $isAdmin && $ticket->user_id !== $actor->id) {
            return response()->json(['detail' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        $wasClosed = $ticket->isClosed();
        if (! $wasClosed) {
            $ticket->status = 'closed';
            $ticket->closed_at = now();
            $ticket->save();
            $this->notifications->ticketClosed($ticket, $actor, $isAdmin);
        }

        $ticket->load([
            'user:id,email,display_name,role',
            'replies.admin:id,email,display_name,role',
            'replies.user:id,email,display_name,role',
        ]);

        return response()->json([
            'status' => 'closed',
            'ticket' => $this->serializeTicket($ticket, true, $isAdmin),
        ]);
    }

    public function reopen(Request $request, string $ticketId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('admin');
        $isAdmin = in_array($actor->role, ['admin', 'super_admin', 'sub_admin'], true);

        $ticket = $this->findTicket($ticketId);
        if (! $ticket) {
            return response()->json(['detail' => 'Ticket not found.', 'code' => 'not_found'], 404);
        }

        if (! $isAdmin && $ticket->user_id !== $actor->id) {
            return response()->json(['detail' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        $wasClosed = $ticket->isClosed();
        if ($wasClosed) {
            $otherActive = HelpTicket::query()
                ->where('user_id', $ticket->user_id)
                ->where('id', '!=', $ticket->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->exists();

            if ($otherActive) {
                return response()->json([
                    'detail' => 'This user already has another active ticket. Close it before reopening this one.',
                    'code' => 'active_ticket_exists',
                ], 422);
            }

            $ticket->status = $ticket->replies()->where('author_role', 'admin')->exists() ? 'answered' : 'open';
            $ticket->closed_at = null;
            $ticket->save();
            $this->notifications->ticketReopened($ticket, $actor, $isAdmin);
        }

        $ticket->load([
            'user:id,email,display_name,role',
            'replies.admin:id,email,display_name,role',
            'replies.user:id,email,display_name,role',
        ]);

        return response()->json([
            'status' => 'reopened',
            'ticket' => $this->serializeTicket($ticket, true, $isAdmin),
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }

        $status = trim((string) $request->query('status', ''));

        $query = HelpTicket::query()
            ->with([
                'user:id,email,display_name,role',
                'replies.admin:id,email,display_name,role',
                'replies.user:id,email,display_name,role',
            ])
            ->orderByDesc('created_at');

        if (in_array($status, ['open', 'answered', 'closed'], true)) {
            $query->where('status', $status);
        }

        $tickets = $query->limit(100)->get()->map(fn (HelpTicket $ticket) => $this->serializeTicket($ticket, true, true));

        return response()->json(['tickets' => $tickets]);
    }

    public function adminShow(Request $request, string $ticketId): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }

        $ticket = $this->findTicket($ticketId);
        if (! $ticket) {
            return response()->json(['detail' => 'Ticket not found.', 'code' => 'not_found'], 404);
        }

        $ticket->load([
            'user:id,email,display_name,role',
            'replies.admin:id,email,display_name,role',
            'replies.user:id,email,display_name,role',
        ]);

        return response()->json(['ticket' => $this->serializeTicket($ticket, true, true)]);
    }

    public function adminReply(Request $request, string $ticketId): JsonResponse
    {
        if ($denied = $this->denyUnlessAdmin($request)) {
            return $denied;
        }

        /** @var User $admin */
        $admin = $request->attributes->get('admin');

        $payload = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = $this->findTicket($ticketId);
        if (! $ticket) {
            return response()->json(['detail' => 'Ticket not found.', 'code' => 'not_found'], 404);
        }

        if ($ticket->isClosed()) {
            return response()->json([
                'detail' => 'This ticket is closed. Reopen it before sending another reply.',
                'code' => 'ticket_closed',
            ], 422);
        }

        HelpTicketReply::query()->create([
            'help_ticket_id' => $ticket->id,
            'admin_id' => $admin->id,
            'user_id' => null,
            'author_role' => 'admin',
            'message' => trim($payload['message']),
        ]);

        $ticket->status = 'answered';
        $ticket->closed_at = null;
        $ticket->save();

        $this->notifications->adminReplied($ticket, $admin);

        $ticket->load([
            'user:id,email,display_name,role',
            'replies.admin:id,email,display_name,role',
            'replies.user:id,email,display_name,role',
        ]);

        return response()->json([
            'status' => 'replied',
            'ticket' => $this->serializeTicket($ticket, true, true),
        ]);
    }

    public function downloadAttachment(Request $request, string $ticketId): StreamedResponse|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->attributes->get('admin');
        $isAdmin = in_array($actor->role, ['admin', 'super_admin', 'sub_admin'], true);

        $ticket = $this->findTicket($ticketId);
        if (! $ticket || ! $ticket->attachment_path) {
            return response()->json(['detail' => 'Attachment not found.', 'code' => 'not_found'], 404);
        }

        if (! $isAdmin && $ticket->user_id !== $actor->id) {
            return response()->json(['detail' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        if (! Storage::disk('local')->exists($ticket->attachment_path)) {
            return response()->json(['detail' => 'Attachment file is missing.', 'code' => 'missing_file'], 404);
        }

        return Storage::disk('local')->download(
            $ticket->attachment_path,
            $ticket->attachment_original_name ?: 'attachment'
        );
    }

    private function activeTicketFor(string $userId): ?HelpTicket
    {
        return HelpTicket::query()
            ->where('user_id', $userId)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->orderByDesc('created_at')
            ->first();
    }

    private function findOwnedTicket(string $ticketId, User $user): ?HelpTicket
    {
        $ticket = $this->findTicket($ticketId);
        if (! $ticket || $ticket->user_id !== $user->id) {
            return null;
        }

        return $ticket;
    }

    private function findTicket(string $ticketId): ?HelpTicket
    {
        return HelpTicket::query()
            ->where(function ($query) use ($ticketId) {
                $query->where('id', $ticketId)->orWhere('ticket_number', $ticketId);
            })
            ->first();
    }

    private function nextTicketNumber(): string
    {
        $prefix = 'HELP-'.now()->format('Ymd').'-';

        $latest = HelpTicket::query()
            ->where('ticket_number', 'like', $prefix.'%')
            ->orderByDesc('ticket_number')
            ->lockForUpdate()
            ->value('ticket_number');

        $seq = 1;
        if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('admin');
        if (! in_array($user->role, ['admin', 'super_admin', 'sub_admin'], true)) {
            return response()->json(['detail' => 'Not allowed.', 'code' => 'forbidden'], 403);
        }

        return null;
    }

    private function serializeTicket(HelpTicket $ticket, bool $withReplies = false, bool $withUser = false): array
    {
        $payload = [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'email' => $ticket->email,
            'subject' => $ticket->subject,
            'message' => $ticket->message,
            'status' => $ticket->status,
            'closed_at' => optional($ticket->closed_at)?->toIso8601String(),
            'can_reply' => $ticket->isOpenForReplies(),
            'has_attachment' => filled($ticket->attachment_path),
            'attachment' => filled($ticket->attachment_path) ? [
                'name' => $ticket->attachment_original_name,
                'mime' => $ticket->attachment_mime,
                'size' => $ticket->attachment_size,
                'download_url' => '/api/help/tickets/'.$ticket->id.'/attachment',
            ] : null,
            'created_at' => optional($ticket->created_at)?->toIso8601String(),
            'updated_at' => optional($ticket->updated_at)?->toIso8601String(),
        ];

        if ($withUser) {
            $payload['user'] = $ticket->user ? [
                'id' => $ticket->user->id,
                'email' => $ticket->user->email,
                'display_name' => $ticket->user->display_name,
                'role' => $ticket->user->role,
            ] : null;
        }

        if ($withReplies) {
            $payload['replies'] = $ticket->relationLoaded('replies')
                ? $ticket->replies->map(fn (HelpTicketReply $reply) => $this->serializeReply($reply))->values()->all()
                : [];
        }

        return $payload;
    }

    private function serializeReply(HelpTicketReply $reply): array
    {
        $author = null;
        if ($reply->author_role === 'user' && $reply->relationLoaded('user') && $reply->user) {
            $author = [
                'id' => $reply->user->id,
                'email' => $reply->user->email,
                'display_name' => $reply->user->display_name,
                'role' => 'user',
            ];
        } elseif ($reply->relationLoaded('admin') && $reply->admin) {
            $author = [
                'id' => $reply->admin->id,
                'email' => $reply->admin->email,
                'display_name' => $reply->admin->display_name,
                'role' => 'admin',
            ];
        }

        return [
            'id' => $reply->id,
            'message' => $reply->message,
            'author_role' => $reply->author_role ?: 'admin',
            'created_at' => optional($reply->created_at)?->toIso8601String(),
            'admin' => $author && ($author['role'] ?? null) === 'admin' ? $author : null,
            'author' => $author,
        ];
    }
}
