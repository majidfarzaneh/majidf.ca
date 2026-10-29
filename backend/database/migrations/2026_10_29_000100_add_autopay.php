<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auto-pay: one saved card per client (the card itself stays in Stripe; we keep only what the
     * portal shows) and the client's consent to charge it for auto-invoiced renewals.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_payment_method_id')->nullable()->after('stripe_customer_id');
            $table->string('card_brand', 20)->nullable()->after('stripe_payment_method_id');
            $table->string('card_last4', 4)->nullable()->after('card_brand');
            $table->unsignedTinyInteger('card_exp_month')->nullable()->after('card_last4');
            $table->unsignedSmallInteger('card_exp_year')->nullable()->after('card_exp_month');
            $table->boolean('autopay')->default(false)->after('card_exp_year');
            $table->timestamp('autopay_consented_at')->nullable()->after('autopay');
        });

        Schema::table('payments', function (Blueprint $table) {
            // The client ticked "save this card" while paying; the card is kept once the payment succeeds.
            $table->boolean('save_card')->default(false)->after('stripe_checkout_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['stripe_payment_method_id', 'card_brand', 'card_last4', 'card_exp_month', 'card_exp_year', 'autopay', 'autopay_consented_at']);
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('save_card');
        });
    }
};
