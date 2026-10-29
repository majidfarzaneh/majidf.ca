<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who pays which tax (App\Support\Tax): a service's override wins, then the client's, then the
     * business default (INVOICE_TAX_RATE / INVOICE_TAX_LABEL). NULL rate = inherit, 0 = no tax.
     */
    public function up(): void
    {
        foreach (['users', 'services'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('tax_rate', 5, 2)->nullable();
                $table->string('tax_label', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['users', 'services'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['tax_rate', 'tax_label']));
        }
    }
};
