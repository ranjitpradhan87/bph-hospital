<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Online bookings are held until the advance payment is made, then confirmed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $t) {
            $t->decimal('advance_amount', 12, 2)->default(0)->after('consultation_fee');
            $t->string('payment_order_id', 60)->nullable()->after('payment_mode')->index();
            $t->string('payment_reference', 60)->nullable()->after('payment_order_id');
            $t->timestamp('paid_at')->nullable()->after('payment_reference');
            $t->timestamp('payment_expires_at')->nullable()->after('paid_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn(['advance_amount', 'payment_order_id', 'payment_reference', 'paid_at', 'payment_expires_at']));
    }
};
