<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Mail\TicketReplyMail;
use App\Models\Activity;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        return TicketResource::collection(
            Ticket::query()->with('user')->withCount('messages')
                ->when($status === 'active', fn ($q) => $q->where('status', '!=', TicketStatus::Resolved))
                ->when($status && $status !== 'active', fn ($q) => $q->where('status', $status))
                ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
                ->orderByRaw('status = ? asc', [TicketStatus::Resolved->value])
                ->orderByDesc('last_activity_at')
                ->get()
        );
    }

    public function show(Ticket $ticket): TicketResource
    {
        return new TicketResource($ticket->load('messages.user', 'user'));
    }

    /** Open a ticket on a client's behalf. */
    public function store(Request $request): TicketResource
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('role', 'client')],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:10000'],
            'notify' => ['boolean'],
        ]);

        $ticket = DB::transaction(function () use ($request, $data) {
            $ticket = Ticket::create([
                'user_id' => $data['user_id'],
                'number' => Ticket::nextNumber(),
                'subject' => $data['subject'],
                'status' => TicketStatus::Open,
                'last_activity_at' => now(),
                'last_admin_reply_at' => now(),
            ]);
            $this->postMessage($ticket, $request->user(), $data['body'], $data['notify'] ?? true);

            return $ticket;
        });

        return $this->show($ticket);
    }

    public function reply(Request $request, Ticket $ticket): TicketResource
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'notify' => ['boolean'],
        ]);

        $this->postMessage($ticket, $request->user(), $data['body'], $data['notify'] ?? true);
        $ticket->update(['last_activity_at' => now(), 'last_admin_reply_at' => now()]);
        if (! empty($data['status'])) {
            $this->changeStatus($ticket, TicketStatus::from($data['status']));
        }

        return $this->show($ticket);
    }

    public function update(Request $request, Ticket $ticket): TicketResource
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(TicketStatus::class)],
            'subject' => ['sometimes', 'string', 'max:160'],
        ]);

        if (isset($data['subject'])) {
            $ticket->update(['subject' => $data['subject']]);
        }
        if (isset($data['status'])) {
            $this->changeStatus($ticket, TicketStatus::from($data['status']));
        }

        return $this->show($ticket);
    }

    private function postMessage(Ticket $ticket, User $admin, string $body, bool $notify): void
    {
        $message = $ticket->messages()->create(['user_id' => $admin->id, 'body' => $body]);

        if ($notify && $ticket->user->wants('tick')) {
            Mail::to($ticket->user)->queue(new TicketReplyMail($message));
        }
    }

    private function changeStatus(Ticket $ticket, TicketStatus $status): void
    {
        if ($ticket->status === $status) {
            return;
        }
        $ticket->update(['status' => $status]);
        Activity::log($ticket->user_id, 'ticket', "Ticket {$ticket->reference()} moved to {$status->label()}", $ticket);
    }
}
