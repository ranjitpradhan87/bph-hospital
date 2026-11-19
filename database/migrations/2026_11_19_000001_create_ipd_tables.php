<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-patient (IPD) register: the hospital's daily case ledger. One row per admission; the money columns of the
 * old spreadsheet (cash, UPI, doctor fee, anaesthesia, X-ray …) are "heads", and each amount is an entry
 * (with its own date) under a head, so "19000 (06.11)" in the sheet becomes one dated entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipd_heads', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('name', 60);
            $t->string('type', 10)->index(); // receipt (money in) | payout (money out) | charge (billed service)
            $t->unsignedSmallInteger('display_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('ipd_admissions', function (Blueprint $t) {
            $t->id();
            $t->date('admission_date')->index();
            $t->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $t->string('patient_name', 150)->index();
            $t->string('bed', 20)->nullable();
            $t->string('case_title', 200)->nullable();
            $t->string('doctors', 255)->nullable();
            $t->string('status', 12)->default('admitted')->index(); // admitted | discharged
            $t->date('discharge_date')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('ipd_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('admission_id')->constrained('ipd_admissions')->cascadeOnDelete();
            $t->foreignId('head_id')->constrained('ipd_heads');
            $t->date('entry_date');
            $t->decimal('amount', 12, 2);
            $t->string('note', 255)->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['entry_date', 'head_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipd_entries');
        Schema::dropIfExists('ipd_admissions');
        Schema::dropIfExists('ipd_heads');
    }
};
