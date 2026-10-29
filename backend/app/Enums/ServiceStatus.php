<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case Active = 'active';
    case InDevelopment = 'in_development';
    case Paused = 'paused';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::InDevelopment => 'In development',
            self::Paused => 'Paused',
            self::Cancelled => 'Cancelled',
        };
    }
}
