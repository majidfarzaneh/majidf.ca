<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['invoice_id', 'service_id', 'description', 'quantity', 'unit_price_cents', 'amount_cents', 'sort'])]
class InvoiceItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
