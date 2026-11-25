<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many days before the renewal date the daily job issues a service's renewal invoice.
     * NULL = the business default (INVOICE_RENEWAL_LEAD_DAYS), 0 = on the renewal date.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('invoice_lead_days')->nullable()->after('auto_invoice');
        });
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('invoice_lead_days'));
    }
};
