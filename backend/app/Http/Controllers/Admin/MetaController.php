<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;

/** Option lists for admin forms, so the frontend never hardcodes enums. */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'service_types' => collect(ServiceType::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label(), 'icon' => $c->icon()]),
            'service_statuses' => collect(ServiceStatus::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()]),
            'billing_cycles' => collect(BillingCycle::cases())->map(fn ($c) => ['value' => $c->value, 'label' => ucfirst(str_replace('_', '-', $c->value))]),
            'payment_methods' => Payment::MANUAL_METHODS,
            'activity_kinds' => array_keys(Activity::KINDS),
            'defaults' => [
                'tax_rate' => config('business.tax_rate'),
                'tax_label' => config('business.tax_label'),
                'payment_terms_days' => config('business.payment_terms_days'),
                'grace_days' => config('business.grace_days'),
                'reminder_days_before' => config('business.reminder_days_before'),
                'next_invoice_number' => Invoice::nextNumber(),
            ],
        ]);
    }
}
