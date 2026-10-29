<?php

namespace App\Http\Controllers\Portal;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Support\AdminAlert;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return TicketResource::collection(
            $request->user()->tickets()->withCount('messages')
                ->orderByRaw('status = ? asc', [TicketStatus::Resolved->value])
                ->orderByDesc('last_activity_at')->get()
        );
    }

    public function show(Request $request, int $number): TicketResource
    {
        $ticket = $request->user()->tickets()->where('number', $number)->firstOrFail();

        return new TicketResource($ticket->load('messages.user'));
    }

    public function store(Request $request): TicketResource
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:10000'],
        ]);
        $user = $request->user();

        $ticket = DB::transaction(function () use ($user, $data) {
            $ticket = $user->tickets()->create([
                'number' => Ticket::nextNumber(),
                'subject' => $data['subject'],
                'status' => TicketStatus::Open,
                'last_activity_at' => now(),
            ]);
            $ticket->messages()->create(['user_id' => $user->id, 'body' => $data['body']]);

            return $ticket;
        });

        Audit::log('ticket.opened', "Opened ticket {$ticket->reference()} · {$ticket->subject}", $ticket);
        AdminAlert::send('ticket', "New ticket {$ticket->reference()} · {$ticket->subject}", [
            'Client' => $user->company ?: $user->name,
            'Message' => Str::limit($data['body'], 600),
        ], '/tickets/'.$ticket->id);

        return new TicketResource($ticket->load('messages.user'));
    }

    public function reply(Request $request, int $number): TicketResource
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $user = $request->user();
        $ticket = $user->tickets()->where('number', $number)->firstOrFail();

        $ticket->messages()->create(['user_id' => $user->id, 'body' => $data['body']]);
        $ticket->update([
            'status' => $ticket->status === TicketStatus::Resolved ? TicketStatus::Open : $ticket->status,
            'last_activity_at' => now(),
        ]);

        Audit::log('ticket.replied', "Replied on ticket {$ticket->reference()}", $ticket);
        AdminAlert::send('ticket', "Reply on {$ticket->reference()} · {$ticket->subject}", [
            'Client' => $user->company ?: $user->name,
            'Message' => Str::limit($data['body'], 600),
        ], '/tickets/'.$ticket->id);

        return new TicketResource($ticket->load('messages.user'));
    }
}
