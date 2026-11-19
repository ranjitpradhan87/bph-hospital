<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipd_admissions', function (Blueprint $t) {
            $t->string('anaesthetist', 150)->nullable()->after('doctors');
        });
    }

    public function down(): void
    {
        Schema::table('ipd_admissions', fn (Blueprint $t) => $t->dropColumn('anaesthetist'));
    }
};
