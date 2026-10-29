<?php

return [
    // Shown on invoices, emails and in the portal.
    'name' => env('BUSINESS_NAME', 'Majid Farzaneh'),
    'owner_name' => env('BUSINESS_OWNER_NAME', 'Majid'),
    'email' => env('BUSINESS_EMAIL', 'majix.fa@gmail.com'),
    'phone' => env('BUSINESS_PHONE', '437-328-3301'),
    'address' => env('BUSINESS_ADDRESS', 'Toronto, ON, Canada'),
    'tax_number' => env('BUSINESS_TAX_NUMBER'),

    // Where admin alerts (new tickets, payments, project requests) are sent.
    'admin_email' => env('ADMIN_EMAIL', env('BUSINESS_EMAIL', 'majix.fa@gmail.com')),
    'admin_name' => env('ADMIN_NAME', 'Majid Farzaneh'),

    'currency' => 'CAD',

    // Shown to clients who choose to pay outside the portal. A method is offered only when it has details.
    'offline' => [
        'etransfer_email' => env('PAY_ETRANSFER_EMAIL', env('BUSINESS_EMAIL', 'majix.fa@gmail.com')),
        'etransfer_autodeposit' => (bool) env('PAY_ETRANSFER_AUTODEPOSIT', true),
        'cheque_payable_to' => env('PAY_CHEQUE_PAYABLE_TO', env('BUSINESS_NAME', 'Majid Farzaneh')),
        'cheque_mail_to' => env('PAY_CHEQUE_MAIL_TO', env('BUSINESS_ADDRESS', 'Toronto, ON, Canada')),
        // Free text, one item per line, e.g. "Bank: RBC\nTransit: 01234\nInstitution: 003\nAccount: 1234567".
        'bank_details' => env('PAY_BANK_DETAILS'),
        'receipt_max_kb' => 10240,
    ],
    'tax_label' => env('INVOICE_TAX_LABEL', 'HST'),
    'tax_rate' => (float) env('INVOICE_TAX_RATE', 13),
    'payment_terms_days' => (int) env('INVOICE_TERMS_DAYS', 14),
    // Default days after the due date an invoice stays payable online (editable per invoice).
    'grace_days' => (int) env('INVOICE_GRACE_DAYS', 3),
    // The "due soon" reminder goes out this many days before the due date.
    'reminder_days_before' => (int) env('INVOICE_REMINDER_DAYS_BEFORE', 3),
    'invoice_prefix' => env('INVOICE_PREFIX', 'INV-'),
    'invoice_start' => (int) env('INVOICE_START', 2001),
    'ticket_start' => (int) env('TICKET_START', 300),
    'renewal_reminder_days' => 14,

    // Public URLs of the three sites (used for links in emails and Stripe redirects).
    // With subdomains: majidf.local, panel.majidf.local, admin.majidf.local. Without: /portal and /admin paths.
    'frontend_url' => rtrim(env('FRONTEND_URL', env('APP_URL', 'http://localhost')), '/'),
    'portal_url' => rtrim(env('PORTAL_URL', rtrim(env('FRONTEND_URL', 'http://localhost'), '/').'/portal'), '/'),
    'admin_url' => rtrim(env('ADMIN_URL', rtrim(env('FRONTEND_URL', 'http://localhost'), '/').'/admin'), '/'),

    // Sign-in codes. Wrong guesses are capped per code; the request/verify rate limits live in AuthController.
    'login_code_ttl_minutes' => 2,
    'login_code_max_attempts' => 5,
    'login_code_resend_seconds' => 60,
];
