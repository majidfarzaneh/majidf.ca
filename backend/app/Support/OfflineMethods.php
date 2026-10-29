<?php

namespace App\Support;

/** Offline payment methods offered to clients, with the instructions to show them. */
class OfflineMethods
{
    /** @return array<string, array{label: string, lines: list<array{0: string, 1: string}>, hint: string}> */
    public static function enabled(): array
    {
        $c = config('business.offline');
        $methods = [];

        if (filled($c['etransfer_email'])) {
            $methods['etransfer'] = [
                'label' => 'Interac e-Transfer',
                'lines' => [['Send to', $c['etransfer_email']]],
                'hint' => $c['etransfer_autodeposit']
                    ? 'Autodeposit is on, so no security question is needed. Put the invoice number in the message.'
                    : 'Put the invoice number in the message, and email me the security answer.',
            ];
        }
        if (filled($c['bank_details'])) {
            $methods['bank'] = [
                'label' => 'Bank transfer',
                'lines' => collect(preg_split('/\\r?\\n|\\\\n/', (string) $c['bank_details']))->filter()->map(function ($line) {
                    [$k, $v] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');

                    return $v === '' ? ['', $k] : [$k, $v];
                })->values()->all(),
                'hint' => 'Use the invoice number as the payment reference.',
            ];
        }
        if (filled($c['cheque_payable_to'])) {
            $methods['cheque'] = [
                'label' => 'Cheque',
                'lines' => array_values(array_filter([['Payable to', $c['cheque_payable_to']], filled($c['cheque_mail_to']) ? ['Mail to', $c['cheque_mail_to']] : null])),
                'hint' => 'Write the invoice number on the memo line, then upload a photo of the cheque.',
            ];
        }

        return $methods;
    }
}
