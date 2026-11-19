<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Text-message outbox/audit trail, and how an appointment will be paid. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('mobile', 15)->index();
            $t->string('template', 40);
            $t->text('body');
            $t->string('driver', 20);
            $t->string('status', 10)->index(); // sent | logged | failed
            $t->string('provider_ref', 100)->nullable();
            $t->string('error', 500)->nullable();
            $t->string('related_type', 30)->nullable();
            $t->unsignedBigInteger('related_id')->nullable();
            $t->timestamps();
            $t->index(['related_type', 'related_id']);
        });

        Schema::table('appointments', function (Blueprint $t) {
            $t->string('payment_mode', 20)->default('pay_at_hospital')->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn('payment_mode'));
        Schema::dropIfExists('sms_messages');
    }
};
