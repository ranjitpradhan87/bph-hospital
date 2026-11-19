<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each doctor can set their own booking advance; blank means the hospital-wide default. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', fn (Blueprint $t) => $t->decimal('advance_amount', 12, 2)->nullable()->after('consultation_fee'));
    }

    public function down(): void
    {
        Schema::table('doctors', fn (Blueprint $t) => $t->dropColumn('advance_amount'));
    }
};
