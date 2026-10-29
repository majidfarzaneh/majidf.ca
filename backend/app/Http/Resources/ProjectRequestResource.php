<?php

namespace App\Http\Resources;

use App\Models\ProjectRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectRequest */
class ProjectRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'types' => $this->types,
            'type_labels' => $this->typeLabels(),
            'budget' => $this->budget,
            'budget_label' => ProjectRequest::BUDGETS[$this->budget] ?? $this->budget,
            'description' => $this->description,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            $this->mergeWhen($admin, fn () => [
                'admin_notes' => $this->admin_notes,
                'user' => new UserResource($this->whenLoaded('user')),
            ]),
        ];
    }
}
