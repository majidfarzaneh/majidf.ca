<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Mail\ServiceAddedMail;
use App\Models\Activity;
use App\Models\Service;
use App\Services\RenewalInvoicer;
use App\Services\UptimeReport;
use App\Support\Money;
use App\Support\Tax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ServiceResource::collection(
            Service::query()->with('user')
                ->when($request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
                ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
                ->when($request->string('type')->toString(), fn ($q, $t) => $q->where('type', $t))
                ->orderBy('user_id')->orderBy('sort')->orderBy('id')
                ->get()
        );
    }

    public function show(Service $service, UptimeReport $uptime): JsonResponse
    {
        return response()->json([
            'data' => new ServiceResource($service->load('user')),
            'uptime' => $service->monitor_url ? $uptime->forServices(collect([$service->id])) : null,
        ]);
    }

    public function store(Request $request): ServiceResource
    {
        $notify = $request->validate(['notify' => ['boolean']])['notify'] ?? true;
        $service = Service::create($this->validated($request));
        Activity::log($service->user_id, 'service', $service->type->label().' added · '.$service->name, $service);

        // "Email the client" in the admin form; untick it when recording a service the client already knows about.
        if ($notify && $service->status !== ServiceStatus::Cancelled && $service->user->wants('svc')) {
            Mail::to($service->user)->queue(new ServiceAddedMail($service));
        }

        return new ServiceResource($service->load('user'));
    }

    public function update(Request $request, Service $service): ServiceResource
    {
        $wasInDevelopment = $service->status === ServiceStatus::InDevelopment;
        $service->update($this->validated($request, $service));

        if ($wasInDevelopment && $service->status === ServiceStatus::Active) {
            Activity::log($service->user_id, 'milestone', $service->name.' is live', $service);
        }

        return new ServiceResource($service->load('user'));
    }

    /**
     * Invoice the next renewal period now instead of waiting for the renewal date. Rolls the renewal
     * date forward one cycle, so the daily job won't bill the same period again.
     */
    public function invoiceRenewal(Request $request, Service $service, RenewalInvoicer $renewals): JsonResponse
    {
        $send = $request->validate(['send' => ['boolean']])['send'] ?? true;
        if ($reason = $renewals->blocker($service)) {
            throw ValidationException::withMessages(['renewal' => $reason]);
        }

        ['invoice' => $invoice, 'payment' => $payment] = $renewals->issue($service, $send);

        return response()->json([
            'invoice' => ['id' => $invoice->id, 'number' => $invoice->number, 'status' => $invoice->status->value],
            'autopay' => $payment?->status, // succeeded, pending or failed when the saved card was charged
            'service' => new ServiceResource($service->refresh()->load('user')),
        ], 201);
    }

    public function destroy(Service $service): JsonResponse
    {
        $service->delete();

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?Service $service = null): array
    {
        $data = $request->validate([
            'user_id' => [$service ? 'sometimes' : 'required', Rule::exists('users', 'id')->where('role', 'client')],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::enum(ServiceType::class)],
            'status' => ['required', Rule::enum(ServiceStatus::class)],
            'detail' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'string', 'max:255'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'billing_cycle' => ['nullable', Rule::enum(BillingCycle::class)],
            'auto_invoice' => ['boolean'],
            'progress' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'progress_label' => ['nullable', 'string', 'max:120'],
            'hours_total' => ['nullable', 'numeric', 'min:0'],
            'hours_used' => ['nullable', 'numeric', 'min:0'],
            'started_at' => ['nullable', 'date'],
            'renews_at' => ['nullable', 'date'],
            'monitor_url' => ['nullable', 'url:http,https', 'max:255'],
            'sort' => ['nullable', 'integer', 'min:0'],
            ...Tax::rules(),
        ]);
        $data = Tax::normalize($data);

        if (array_key_exists('price', $data)) {
            $data['price_cents'] = $data['price'] === null ? null : Money::toCents($data['price']);
            unset($data['price']);
        }
        if (($data['auto_invoice'] ?? false) && ! (BillingCycle::tryFrom($data['billing_cycle'] ?? '')?->isRecurring() && isset($data['renews_at'], $data['price_cents']))) {
            throw ValidationException::withMessages(['auto_invoice' => 'Auto-invoicing needs a recurring billing cycle, a price and a renewal date.']);
        }
        // Reset the reminder when the renewal date moves.
        if ($service && isset($data['renews_at']) && $service->renews_at?->toDateString() !== $data['renews_at']) {
            $service->renewal_reminded_at = null;
        }

        return $data;
    }
}
