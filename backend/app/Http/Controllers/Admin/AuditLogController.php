<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ProjectRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The admin activity log: everything clients, the admin and the system did, newest first. */
class AuditLogController extends Controller
{
    /** Event families the log can be filtered by (the part before the first dot). */
    public const CATEGORIES = [
        'auth' => 'Sign-ins', 'invoice' => 'Invoices', 'payment' => 'Payments', 'autopay' => 'Auto-pay',
        'service' => 'Services', 'client' => 'Clients', 'ticket' => 'Tickets', 'request' => 'Project requests',
        'profile' => 'Client profiles', 'timeline' => 'Timeline posts',
    ];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'actor' => ['nullable', 'in:client,admin,system'],
            'category' => ['nullable', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $page = AuditLog::query()
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['actor'] ?? null, fn ($q, $a) => $q->where('actor_type', $a))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('event', 'like', $c.'.%'))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('text', 'like', "%{$s}%")->orWhere('client_name', 'like', "%{$s}%")
                ->orWhere('actor_name', 'like', "%{$s}%")->orWhere('ip', $s)))
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'at' => $log->created_at->toIso8601String(),
                'event' => $log->event,
                'text' => $log->text,
                'actor' => ['type' => $log->actor_type, 'id' => $log->actor_id, 'name' => $log->actor_name],
                'client' => $log->client_id || $log->client_name ? ['id' => $log->client_id, 'name' => $log->client_name] : null,
                'ip' => $log->ip,
                'meta' => $log->meta,
                'path' => $this->path($log),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'categories' => self::CATEGORIES,
        ]);
    }

    /** The admin page about the entry's subject, when there is one. */
    private function path(AuditLog $log): ?string
    {
        return match ($log->subject_type) {
            (new Invoice)->getMorphClass() => '/invoices/'.$log->subject_id,
            (new Ticket)->getMorphClass() => '/tickets/'.$log->subject_id,
            (new Payment)->getMorphClass() => '/payments?status=all',
            (new ProjectRequest)->getMorphClass() => '/requests',
            (new User)->getMorphClass() => $log->client_id ? '/clients/'.$log->client_id : null,
            default => $log->client_id ? '/clients/'.$log->client_id : null,
        };
    }
}
