<?php

namespace App\Services;

use App\Models\HelpNotification;
use App\Models\HelpTicket;
use App\Models\User;
use Illuminate\Support\Collection;

class HelpNotificationService
{
    public function notifyAdmins(
        HelpTicket $ticket,
        string $type,
        string $title,
        string $body,
        ?string $exceptUserId = null
    ): void {
        $adminIds = User::query()
            ->whereIn('role', ['admin', 'super_admin', 'sub_admin'])
            ->when($exceptUserId, fn ($query) => $query->where('id', '!=', $exceptUserId))
            ->pluck('id');

        $this->createMany($adminIds, $ticket, $type, $title, $body);
    }

    public function notifyUser(
        string $userId,
        HelpTicket $ticket,
        string $type,
        string $title,
        string $body,
        ?string $exceptUserId = null
    ): void {
        if ($exceptUserId && $exceptUserId === $userId) {
            return;
        }

        $this->createMany(collect([$userId]), $ticket, $type, $title, $body);
    }

    public function ticketCreated(HelpTicket $ticket, User $actor): void
    {
        $this->notifyAdmins(
            $ticket,
            'ticket_created',
            'New help ticket',
            sprintf('%s opened %s: %s', $actor->email ?: 'A user', $ticket->ticket_number, $ticket->subject),
            $actor->id
        );
    }

    public function userReplied(HelpTicket $ticket, User $actor): void
    {
        $this->notifyAdmins(
            $ticket,
            'user_replied',
            'User replied on a ticket',
            sprintf('%s replied on %s: %s', $actor->email ?: 'A user', $ticket->ticket_number, $ticket->subject),
            $actor->id
        );
    }

    public function adminReplied(HelpTicket $ticket, User $actor): void
    {
        $this->notifyUser(
            $ticket->user_id,
            $ticket,
            'admin_replied',
            'Support replied to your ticket',
            sprintf('Support replied on %s: %s', $ticket->ticket_number, $ticket->subject),
            $actor->id
        );
    }

    public function ticketClosed(HelpTicket $ticket, User $actor, bool $actorIsAdmin): void
    {
        if ($actorIsAdmin) {
            $this->notifyUser(
                $ticket->user_id,
                $ticket,
                'ticket_closed',
                'Your ticket was closed',
                sprintf('%s was closed by support.', $ticket->ticket_number),
                $actor->id
            );

            return;
        }

        $this->notifyAdmins(
            $ticket,
            'ticket_closed',
            'Ticket closed by user',
            sprintf('%s closed %s: %s', $actor->email ?: 'A user', $ticket->ticket_number, $ticket->subject),
            $actor->id
        );
    }

    public function ticketReopened(HelpTicket $ticket, User $actor, bool $actorIsAdmin): void
    {
        if ($actorIsAdmin) {
            $this->notifyUser(
                $ticket->user_id,
                $ticket,
                'ticket_reopened',
                'Your ticket was reopened',
                sprintf('%s was reopened by support.', $ticket->ticket_number),
                $actor->id
            );

            return;
        }

        $this->notifyAdmins(
            $ticket,
            'ticket_reopened',
            'Ticket reopened by user',
            sprintf('%s reopened %s: %s', $actor->email ?: 'A user', $ticket->ticket_number, $ticket->subject),
            $actor->id
        );
    }

    /**
     * @param  Collection<int, string>  $userIds
     */
    private function createMany(
        Collection $userIds,
        HelpTicket $ticket,
        string $type,
        string $title,
        string $body
    ): void {
        $now = now();
        $rows = $userIds
            ->unique()
            ->filter()
            ->map(fn (string $userId) => [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $userId,
                'help_ticket_id' => $ticket->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

        HelpNotification::query()->insert($rows);
    }
}
