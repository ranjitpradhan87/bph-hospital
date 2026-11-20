<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A first-time visitor is registered only once their advance is paid. Until then the held booking carries
 * the name and mobile they typed and has no patient record at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $t) {
            $t->string('guest_name', 150)->nullable()->after('patient_id');
            $t->string('guest_mobile', 15)->nullable()->after('guest_name');
        });
        DB::statement('ALTER TABLE appointments MODIFY patient_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::table('appointments')->whereNull('patient_id')->delete();
        DB::statement('ALTER TABLE appointments MODIFY patient_id BIGINT UNSIGNED NOT NULL');
        Schema::table('appointments', function (Blueprint $t) {
            $t->dropColumn(['guest_name', 'guest_mobile']);
        });
    }
};
