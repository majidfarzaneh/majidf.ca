<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = (bool) $request->user()?->isAdmin();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'company' => $this->company,
            'phone' => $this->phone,
            'address' => $this->address,
            'initials' => $this->initials(),
            'prefs' => $this->prefs(),
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            $this->mergeWhen($admin, fn () => [
                'admin_notes' => $this->admin_notes,
                'last_login_ip' => $this->last_login_ip,
                'services_count' => $this->services_count,
                'open_tickets_count' => $this->open_tickets_count,
                'outstanding_cents' => isset($this->outstanding_cents) ? (int) $this->outstanding_cents : null,
            ]),
        ];
    }
}
