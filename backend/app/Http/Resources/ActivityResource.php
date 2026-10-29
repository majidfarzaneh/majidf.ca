<?php

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        [$icon, $color] = Activity::KINDS[$this->kind] ?? Activity::KINDS['note'];

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'icon' => $icon,
            'color' => $color,
            'text' => $this->text,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
