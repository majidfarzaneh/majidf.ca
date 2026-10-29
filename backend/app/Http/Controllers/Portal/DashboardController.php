<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\InvoiceResource;
use App\Services\UptimeReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function __invoke(Request $request, UptimeReport $uptime): JsonResponse
    {
        $user = $request->user();
        $services = $user->services()->where('status', '!=', ServiceStatus::Cancelled)->orderBy('sort')->get();
        $due = $user->invoices()->open()->with('payments')->orderBy('due_at')->get();
        $tickets = $user->tickets();

        $next = $services->filter(fn ($s) => $s->renews_at && $s->renews_at->gte(today()))
            ->sortBy('renews_at')->first();

        return response()->json([
            'kpis' => [
                'outstanding_cents' => (int) $due->sum('total_cents'),
                'due_count' => $due->count(),
                'overdue_count' => $due->filter->isOverdue()->count(),
                'expired_count' => $due->filter->isExpired()->count(),
                'active_services' => $services->where('status', ServiceStatus::Active)->count(),
                'in_development' => $services->where('status', ServiceStatus::InDevelopment)->count(),
                'open_tickets' => (clone $tickets)->where('status', '!=', TicketStatus::Resolved)->count(),
                'last_reply_at' => ($last = (clone $tickets)->max('last_admin_reply_at')) ? Carbon::parse($last)->toIso8601String() : null,
                'next_renewal' => $next ? [
                    'date' => $next->renews_at->toDateString(),
                    'name' => $next->type->label().' · '.$next->name,
                    'price_label' => $next->priceLabel(),
                ] : null,
            ],
            'due_invoices' => InvoiceResource::collection($due->take(3)),
            'progress' => $services->filter(fn ($s) => $s->progressPercent() !== null)->take(3)->map(fn ($s) => [
                'id' => $s->id,
                'label' => $s->name.($s->progress_label ? ' · '.$s->progress_label : ''),
                'percent' => $s->progressPercent(),
                'kind' => $s->hours_total ? 'hours' : 'milestone',
            ])->values(),
            'uptime' => $uptime->forServices($services->whereNotNull('monitor_url')->pluck('id')),
            'activity' => ActivityResource::collection($user->activities()->latest()->latest('id')->limit(6)->get()),
        ]);
    }
}
