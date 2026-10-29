<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Days after the due date the client can still pay online.
            $table->unsignedSmallInteger('grace_days')->default(3)->after('due_at');
            // due_at + grace_days, stored so it can be queried directly.
            $table->date('pay_until')->nullable()->after('grace_days')->index();
            // Automatic reminders already sent: {"due_soon": "...", "due_today": "...", "overdue": "...", "closed": "..."}.
            $table->json('reminders')->nullable()->after('reminded_at');
        });

        DB::table('invoices')->whereNotNull('due_at')->orderBy('id')->each(function ($invoice) {
            DB::table('invoices')->where('id', $invoice->id)->update([
                'pay_until' => Carbon::parse($invoice->due_at)->addDays($invoice->grace_days)->toDateString(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['pay_until']);
            $table->dropColumn(['grace_days', 'pay_until', 'reminders']);
        });
    }
};
