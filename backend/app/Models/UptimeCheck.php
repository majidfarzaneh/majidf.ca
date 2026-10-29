<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['service_id', 'is_up', 'status_code', 'response_ms', 'checked_at'])]
class UptimeCheck extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_up' => 'boolean', 'checked_at' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
