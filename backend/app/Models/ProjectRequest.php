<?php

namespace App\Models;

use App\Enums\ProjectRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'types', 'budget', 'description', 'status', 'admin_notes'])]
class ProjectRequest extends Model
{
    public const TYPES = [
        'web' => 'Website', 'app' => 'Mobile app', 'shop' => 'Online store',
        'api' => 'Backend / API', 'host' => 'Hosting + care', 'other' => 'Something else',
    ];

    public const BUDGETS = ['b1' => '< 5k', 'b2' => '5–15k', 'b3' => '15–40k', 'b4' => '40k+'];

    protected function casts(): array
    {
        return ['types' => 'array', 'status' => ProjectRequestStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public function typeLabels(): array
    {
        return array_values(array_map(fn ($t) => self::TYPES[$t] ?? $t, $this->types ?? []));
    }
}
