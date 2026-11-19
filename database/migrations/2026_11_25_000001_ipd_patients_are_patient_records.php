<?php

use App\Models\Patient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every in-patient is a registered patient. A patient admitted without a mobile number (the paper register never
 * recorded one) is registered with the number blank and shows as "details pending" until staff complete it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', fn (Blueprint $t) => $t->string('mobile', 15)->nullable()->change());

        // Admissions typed in before this change get a patient record; the same name becomes one patient.
        $made = [];
        foreach (DB::table('ipd_admissions')->whereNull('patient_id')->whereNull('deleted_at')->orderBy('id')->get(['id', 'patient_name']) as $a) {
            $key = mb_strtolower(trim($a->patient_name));
            if (! isset($made[$key])) {
                $id = DB::table('patients')->insertGetId(['patient_code' => 'TMP-'.bin2hex(random_bytes(8)), 'full_name' => trim($a->patient_name), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('patients')->where('id', $id)->update(['patient_code' => Patient::codeFor($id)]);
                $made[$key] = $id;
            }
            DB::table('ipd_admissions')->where('id', $a->id)->update(['patient_id' => $made[$key]]);
        }
    }

    public function down(): void
    {
        // Patients created here keep existing; a mobile column that must be filled cannot be restored over blank rows.
    }
};
