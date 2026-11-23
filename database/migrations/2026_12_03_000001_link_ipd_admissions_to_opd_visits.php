<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An IPD admission remembers the OPD visit it came from, and carries the admission details the ward needs. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipd_admissions', function (Blueprint $t) {
            $t->foreignId('opd_visit_id')->nullable()->after('patient_id')->constrained('opd_visits')->nullOnDelete();
            $t->time('admission_time')->nullable()->after('admission_date');
            $t->string('admission_type', 20)->nullable()->after('status'); // emergency | planned | transfer
            $t->string('room', 30)->nullable()->after('bed');
            $t->string('attendant_name', 150)->nullable();
            $t->string('attendant_mobile', 15)->nullable();
            $t->string('attendant_relation', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ipd_admissions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('opd_visit_id');
            $t->dropColumn(['admission_time', 'admission_type', 'room', 'attendant_name', 'attendant_mobile', 'attendant_relation']);
        });
    }
};
