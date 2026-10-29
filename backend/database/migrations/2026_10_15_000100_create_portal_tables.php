<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('status', 32)->default('active');
            $table->string('detail')->nullable();
            $table->string('meta')->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->string('billing_cycle', 16)->nullable();
            $table->boolean('auto_invoice')->default(false);
            $table->decimal('progress', 5, 2)->nullable();
            $table->string('progress_label')->nullable();
            $table->decimal('hours_total', 8, 2)->nullable();
            $table->decimal('hours_used', 8, 2)->nullable();
            $table->date('started_at')->nullable();
            $table->date('renews_at')->nullable();
            $table->timestamp('renewal_reminded_at')->nullable();
            $table->string('monitor_url')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_up');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->timestamp('checked_at')->index();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32)->unique();
            $table->string('status', 16)->default('draft')->index();
            $table->string('description');
            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->char('currency', 3)->default('CAD');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('tax_label', 32)->nullable();
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('sort')->default(0);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3)->default('CAD');
            $table->string('method', 32);
            $table->string('status', 16)->default('pending')->index();
            $table->string('reference')->nullable();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->text('note')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_payment', function (Blueprint $table) {
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->primary(['invoice_id', 'payment_id']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number')->unique();
            $table->string('subject');
            $table->string('status', 16)->default('open')->index();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_admin_reply_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('project_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('types');
            $table->string('budget', 16);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('new')->index();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('text');
            $table->nullableMorphs('subject');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['activities', 'project_requests', 'ticket_messages', 'tickets', 'invoice_payment', 'payments', 'invoice_items', 'invoices', 'uptime_checks', 'services', 'login_codes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
