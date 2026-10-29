<?php

namespace App\Http\Resources;

use App\Models\Service;
use App\Services\RenewalInvoicer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'icon' => $this->type->icon(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'detail' => $this->detail,
            'meta' => $this->meta,
            'price_cents' => $this->price_cents,
            'price_label' => $this->priceLabel(),
            'billing_cycle' => $this->billing_cycle?->value,
            'progress' => $this->progressPercent(),
            'progress_label' => $this->progress_label,
            'hours_total' => $this->hours_total,
            'hours_used' => $this->hours_used,
            'started_at' => $this->started_at?->toDateString(),
            'renews_at' => $this->renews_at?->toDateString(),
            'monitored' => filled($this->monitor_url),
            $this->mergeWhen($admin, fn () => [
                'user_id' => $this->user_id,
                'user' => new UserResource($this->whenLoaded('user')),
                'auto_invoice' => $this->auto_invoice,
                // The period "Invoice renewal" would bill now, or why it can't.
                'renewal' => $this->renewalPreview(),
                'monitor_url' => $this->monitor_url,
                'raw_progress' => $this->progress,
                'sort' => $this->sort,
            ]),
        ];
    }

    /** @return array{blocked: string}|array{from: string, to: string} */
    private function renewalPreview(): array
    {
        if ($blocked = app(RenewalInvoicer::class)->blocker($this->resource)) {
            return ['blocked' => $blocked];
        }

        return ['from' => $this->renews_at->toDateString(), 'to' => $this->billing_cycle->advance($this->renews_at)->subDay()->toDateString()];
    }
}
