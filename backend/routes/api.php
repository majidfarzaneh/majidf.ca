<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Portal;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

// A coarse per-IP flood guard; the per-email and per-IP sign-in code limits are in AuthController.
Route::prefix('auth')->middleware('throttle:30,1')->group(function () {
    Route::post('code', [AuthController::class, 'requestCode']);
    Route::post('verify', [AuthController::class, 'verify']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);

    Route::prefix('portal')->group(function () {
        Route::get('dashboard', Portal\DashboardController::class);
        Route::get('services', [Portal\ServiceController::class, 'index']);
        Route::get('invoices', [Portal\InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}/pdf', [Portal\InvoiceController::class, 'pdf']);
        Route::post('payments/intent', [Portal\PaymentController::class, 'intent'])->middleware('throttle:20,1');
        Route::post('payments/checkout', [Portal\PaymentController::class, 'checkout'])->middleware('throttle:20,1');
        Route::post('payments/offline', [Portal\PaymentController::class, 'offline'])->middleware('throttle:10,1');
        Route::get('payments/{payment}', [Portal\PaymentController::class, 'show']);
        Route::get('payments/{payment}/receipt', [Portal\PaymentController::class, 'receipt']);
        Route::post('payments/{payment}/save-card', [Portal\PaymentMethodController::class, 'saveOnPayment'])->middleware('throttle:20,1');
        Route::post('payment-method/setup', [Portal\PaymentMethodController::class, 'setup'])->middleware('throttle:10,1');
        Route::post('payment-method', [Portal\PaymentMethodController::class, 'store'])->middleware('throttle:10,1');
        Route::put('payment-method/autopay', [Portal\PaymentMethodController::class, 'autopay']);
        Route::delete('payment-method', [Portal\PaymentMethodController::class, 'destroy']);
        Route::get('tickets', [Portal\TicketController::class, 'index']);
        Route::post('tickets', [Portal\TicketController::class, 'store'])->middleware('throttle:20,1');
        Route::get('tickets/{number}', [Portal\TicketController::class, 'show'])->whereNumber('number');
        Route::post('tickets/{number}/messages', [Portal\TicketController::class, 'reply'])->whereNumber('number')->middleware('throttle:30,1');
        Route::post('project-requests', [Portal\ProjectRequestController::class, 'store'])->middleware('throttle:10,1');
        Route::put('profile', [Portal\ProfileController::class, 'update']);
        Route::put('profile/notifications', [Portal\ProfileController::class, 'notifications']);
    });

    Route::prefix('admin')->middleware('admin')->group(function () {
        Route::get('dashboard', Admin\DashboardController::class);
        Route::get('meta', Admin\MetaController::class);

        Route::apiResource('clients', Admin\ClientController::class);
        Route::post('clients/{client}/invite', [Admin\ClientController::class, 'invite']);

        Route::apiResource('services', Admin\ServiceController::class);
        Route::post('services/{service}/renewal-invoice', [Admin\ServiceController::class, 'invoiceRenewal']);

        Route::apiResource('invoices', Admin\InvoiceController::class);
        Route::post('invoices/{invoice}/send', [Admin\InvoiceController::class, 'send']);
        Route::post('invoices/{invoice}/remind', [Admin\InvoiceController::class, 'remind']);
        Route::post('invoices/{invoice}/void', [Admin\InvoiceController::class, 'void']);
        Route::post('invoices/{invoice}/payments', [Admin\InvoiceController::class, 'recordPayment']);
        Route::get('invoices/{invoice}/pdf', [Admin\InvoiceController::class, 'pdf']);

        Route::get('payments', [Admin\PaymentController::class, 'index']);
        Route::get('payments/{payment}', [Admin\PaymentController::class, 'show']);
        Route::post('payments/{payment}/approve', [Admin\PaymentController::class, 'approve']);
        Route::post('payments/{payment}/reject', [Admin\PaymentController::class, 'reject']);
        Route::get('payments/{payment}/receipt', [Admin\PaymentController::class, 'receipt']);

        Route::apiResource('tickets', Admin\TicketController::class)->except('destroy');
        Route::post('tickets/{ticket}/messages', [Admin\TicketController::class, 'reply']);

        Route::apiResource('project-requests', Admin\ProjectRequestController::class)->only(['index', 'update', 'destroy']);

        Route::get('activity-log', [Admin\AuditLogController::class, 'index']);
        Route::get('notifications', [Admin\NotificationController::class, 'index']);
        Route::post('notifications/read-all', [Admin\NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [Admin\NotificationController::class, 'read']);

        Route::post('activities', [Admin\ActivityController::class, 'store']);
        Route::delete('activities/{activity}', [Admin\ActivityController::class, 'destroy']);
    });
});
