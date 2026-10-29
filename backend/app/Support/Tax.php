<?php

namespace App\Support;

use App\Models\Service;
use App\Models\User;

/**
 * The tax an invoice should carry. A service's own setting wins, then the client's, then the
 * business default. A NULL rate means "inherit"; 0 means no tax (the PDF then shows no tax row).
 */
class Tax
{
    /** @return array{rate: float, label: string, source: 'service'|'client'|'default'} */
    public static function for(?User $client, ?Service $service = null): array
    {
        $default = (string) config('business.tax_label');

        if ($service?->tax_rate !== null) {
            return ['rate' => (float) $service->tax_rate, 'label' => $service->tax_label ?: ($client?->tax_label ?: $default), 'source' => 'service'];
        }
        if ($client?->tax_rate !== null) {
            return ['rate' => (float) $client->tax_rate, 'label' => $client->tax_label ?: $default, 'source' => 'client'];
        }

        return ['rate' => (float) config('business.tax_rate'), 'label' => $default, 'source' => 'default'];
    }

    /** Validation for a client's or service's tax override. */
    public static function rules(): array
    {
        return [
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:50'],
            'tax_label' => ['nullable', 'string', 'max:32'],
        ];
    }

    /** Inheriting means no label of its own either. */
    public static function normalize(array $data): array
    {
        if (array_key_exists('tax_rate', $data) && $data['tax_rate'] === null) {
            $data['tax_label'] = null;
        }

        return $data;
    }
}
