<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Offline payments (e-Transfer, cheque, bank transfer) are submitted by the client with a receipt
        // and sit in status "review" until the admin approves (→ succeeded) or rejects (→ rejected) them.
        Schema::table('payments', function (Blueprint $table) {
            $table->text('client_note')->nullable()->after('note');
            $table->string('receipt_path')->nullable()->after('client_note');
            $table->string('receipt_name')->nullable()->after('receipt_path');
            $table->string('receipt_mime', 100)->nullable()->after('receipt_name');
            $table->unsignedInteger('receipt_size')->nullable()->after('receipt_mime');
            $table->timestamp('submitted_at')->nullable()->after('receipt_size');
            $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['client_note', 'receipt_path', 'receipt_name', 'receipt_mime', 'receipt_size', 'submitted_at', 'reviewed_at', 'rejection_reason']);
        });
    }
};
