<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Outpatient (OPD) visits. A visit starts from a booked appointment or a walk-in, gets a token for the day, is billed
 * for the consultation, may produce lab orders, and carries the doctor's consultation sheet and prescription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opd_visits', function (Blueprint $t) {
            $t->id();
            $t->date('visit_date');
            $t->unsignedInteger('token_number');
            $t->foreignId('patient_id')->constrained();
            $t->foreignId('doctor_id')->constrained();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('appointment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->string('source', 12)->default('walk_in'); // walk_in | appointment
            $t->string('status', 20)->default('waiting'); // waiting | in_consultation | completed | cancelled
            $t->decimal('fee', 12, 2)->default(0);
            $t->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $t->string('reason', 500)->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['visit_date', 'doctor_id', 'token_number']);
            $t->index(['visit_date', 'status']);
            $t->index(['patient_id', 'visit_date']);
        });

        // The consultation sheet and prescription belong to the visit; an appointment is no longer required for one.
        Schema::table('consultations', fn (Blueprint $t) => $t->foreignId('opd_visit_id')->nullable()->unique()->after('appointment_id')->constrained('opd_visits')->nullOnDelete());
        DB::statement('ALTER TABLE consultations MODIFY appointment_id BIGINT UNSIGNED NULL');

        Schema::table('lab_orders', fn (Blueprint $t) => $t->foreignId('opd_visit_id')->nullable()->after('admission_id')->constrained('opd_visits')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('lab_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('opd_visit_id');
        });
        Schema::table('consultations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('opd_visit_id');
        });
        Schema::dropIfExists('opd_visits');
    }
};
