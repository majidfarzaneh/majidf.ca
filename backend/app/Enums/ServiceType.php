<?php

namespace App\Enums;

enum ServiceType: string
{
    case Website = 'website';
    case MobileApp = 'mobile_app';
    case Hosting = 'hosting';
    case Domain = 'domain';
    case Maintenance = 'maintenance';
    case Ssl = 'ssl';
    case Development = 'development';
    case Email = 'email';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Website => 'Website',
            self::MobileApp => 'Mobile app',
            self::Hosting => 'Hosting',
            self::Domain => 'Domain',
            self::Maintenance => 'Maintenance',
            self::Ssl => 'SSL',
            self::Development => 'Development',
            self::Email => 'Email',
            self::Other => 'Service',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Website => 'fa-solid fa-globe',
            self::MobileApp => 'fa-solid fa-mobile-screen',
            self::Hosting => 'fa-solid fa-server',
            self::Domain => 'fa-solid fa-at',
            self::Maintenance => 'fa-solid fa-screwdriver-wrench',
            self::Ssl => 'fa-solid fa-lock',
            self::Development => 'fa-solid fa-clock',
            self::Email => 'fa-solid fa-envelope',
            self::Other => 'fa-solid fa-cube',
        };
    }
}
