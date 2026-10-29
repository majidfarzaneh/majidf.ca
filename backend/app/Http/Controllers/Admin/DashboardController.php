<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectRequestStatus;
use App\Enums\Role;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\TicketResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ProjectRequest;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $unpaid = Invoice::query()->open()->with('user', 'payments')->orderBy('due_at')->get();
        $overdue = $unpaid->filter->isOverdue();
        $paid = Payment::query()->where('status', 'succeeded');

        $mrr = Service::query()->where('status', ServiceStatus::Active)->whereNotNull('price_cents')->get()
            ->sum(fn (Service $s) => match ($s->billing_cycle) {
                BillingCycle::Monthly => $s->price_cents,
                BillingCycle::Quarterly => $s->price_cents / 3,
                BillingCycle::Yearly => $s->price_cents / 12,
                default => 0,
            });

        $awaiting = Ticket::query()->with('user')->withCount('messages')
            ->where('status', '!=', TicketStatus::Resolved)
            ->where(fn ($q) => $q->whereNull('last_admin_reply_at')->orWhereColumn('last_activity_at', '>', 'last_admin_reply_at'))
            ->orderBy('last_activity_at')->get();

        $start = now()->startOfMonth()->subMonths(11);
        $byMonth = (clone $paid)->where('paid_at', '>=', $start)->get(['amount_cents', 'paid_at'])
            ->groupBy(fn ($p) => $p->paid_at->format('Y-m'));

        return response()->json([
            'kpis' => [
                'outstanding_cents' => (int) $unpaid->sum('total_cents'),
                'unpaid_count' => $unpaid->count(),
                'overdue_cents' => (int) $overdue->sum('total_cents'),
                'overdue_count' => $overdue->count(),
                'revenue_month_cents' => (int) (clone $paid)->where('paid_at', '>=', now()->startOfMonth())->sum('amount_cents'),
                'revenue_year_cents' => (int) (clone $paid)->where('paid_at', '>=', now()->startOfYear())->sum('amount_cents'),
                'mrr_cents' => (int) round($mrr),
                'clients' => User::query()->where('role', Role::Client)->where('is_active', true)->count(),
                'open_tickets' => Ticket::query()->where('status', '!=', TicketStatus::Resolved)->count(),
                'awaiting_reply' => $awaiting->count(),
                'new_requests' => ProjectRequest::query()->where('status', ProjectRequestStatus::New)->count(),
                'drafts' => Invoice::query()->where('status', InvoiceStatus::Draft)->count(),
                'payments_to_review' => Payment::query()->where('status', 'review')->count(),
            ],
            'revenue_by_month' => collect(range(0, 11))->map(function ($i) use ($start, $byMonth) {
                $month = $start->copy()->addMonths($i);

                return ['month' => $month->format('Y-m'), 'cents' => (int) ($byMonth->get($month->format('Y-m'))?->sum('amount_cents') ?? 0)];
            }),
            'overdue_invoices' => InvoiceResource::collection($overdue->take(6)->values()),
            'awaiting_tickets' => TicketResource::collection($awaiting->take(6)),
            'review_payments' => PaymentResource::collection(
                Payment::query()->where('status', 'review')->with('user', 'invoices')->oldest('submitted_at')->limit(6)->get()
            ),
            'recent_payments' => PaymentResource::collection(
                (clone $paid)->with('user', 'invoices')->latest('paid_at')->limit(6)->get()
            ),
            'upcoming_renewals' => ServiceResource::collection(
                Service::query()->with('user')->whereIn('status', [ServiceStatus::Active, ServiceStatus::InDevelopment])
                    ->whereBetween('renews_at', [today(), today()->addDays(30)])->orderBy('renews_at')->limit(8)->get()
            ),
        ]);
    }
}
