<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** IPD admissions point at real doctors, departments and beds; money entries gain a payment mode and receipt number. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipd_beds', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('ward', 60)->nullable();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->integer('display_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::table('ipd_admissions', function (Blueprint $t) {
            $t->foreignId('bed_id')->nullable()->after('bed')->constrained('ipd_beds')->nullOnDelete();
            $t->foreignId('department_id')->nullable()->after('bed_id')->constrained()->nullOnDelete();
            $t->foreignId('anaesthetist_id')->nullable()->after('anaesthetist')->constrained('doctors')->nullOnDelete();
        });

        Schema::create('ipd_admission_doctors', function (Blueprint $t) {
            $t->foreignId('admission_id')->constrained('ipd_admissions')->cascadeOnDelete();
            $t->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $t->primary(['admission_id', 'doctor_id']);
        });

        Schema::table('ipd_entries', function (Blueprint $t) {
            $t->string('payment_mode', 10)->nullable()->after('amount');
            $t->string('receipt_no', 40)->nullable()->after('payment_mode');
        });

        // Keep what was typed before: every distinct bed label becomes a bed, and the admission points at it.
        $order = 0;
        foreach (DB::table('ipd_admissions')->whereNotNull('bed')->where('bed', '!=', '')->distinct()->orderBy('bed')->pluck('bed') as $raw) {
            $code = strtoupper(trim($raw));
            $id = DB::table('ipd_beds')->where('code', $code)->value('id')
                ?? DB::table('ipd_beds')->insertGetId(['code' => $code, 'display_order' => ++$order, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ipd_admissions')->where('bed', $raw)->update(['bed_id' => $id, 'bed' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('ipd_entries', fn (Blueprint $t) => $t->dropColumn(['payment_mode', 'receipt_no']));
        Schema::dropIfExists('ipd_admission_doctors');
        Schema::table('ipd_admissions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('anaesthetist_id');
            $t->dropConstrainedForeignId('department_id');
            $t->dropConstrainedForeignId('bed_id');
        });
        Schema::dropIfExists('ipd_beds');
    }
};
