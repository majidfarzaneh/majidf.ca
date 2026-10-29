<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum BillingCycle: string
{
    case OneTime = 'one_time';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case Hourly = 'hourly';
    case Included = 'included';

    public function suffix(): string
    {
        return match ($this) {
            self::Monthly => ' / mo',
            self::Quarterly => ' / qtr',
            self::Yearly => ' / yr',
            self::Hourly => ' / h',
            default => '',
        };
    }

    public function isRecurring(): bool
    {
        return in_array($this, [self::Monthly, self::Quarterly, self::Yearly], true);
    }

    public function advance(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Monthly => $date->copy()->addMonthNoOverflow(),
            self::Quarterly => $date->copy()->addMonthsNoOverflow(3),
            self::Yearly => $date->copy()->addYearNoOverflow(),
            default => $date,
        };
    }
}
