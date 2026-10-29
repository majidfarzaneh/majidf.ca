<?php

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'reference' => $this->reference(),
            'subject' => $this->subject,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'message_count' => $this->messages_count ?? ($this->relationLoaded('messages') ? $this->messages->count() : null),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'last_admin_reply_at' => $this->last_admin_reply_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($m) => [
                'id' => $m->id,
                'body' => $m->body,
                'from_admin' => (bool) $m->user?->isAdmin(),
                'author' => $m->user?->isAdmin() ? config('business.owner_name') : strtok($m->user?->name ?? 'Client', ' '),
                'created_at' => $m->created_at?->toIso8601String(),
            ])),
            'user' => $this->when($request->user()?->isAdmin(), fn () => new UserResource($this->whenLoaded('user'))),
        ];
    }
}
