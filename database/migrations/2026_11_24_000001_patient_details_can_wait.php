<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A patient who books online gives only a name and mobile number; hospital staff fill in the rest later. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $t) {
            $t->date('date_of_birth')->nullable()->change();
            $t->string('gender', 16)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $t) {
            $t->date('date_of_birth')->nullable(false)->change();
            $t->string('gender', 16)->nullable(false)->change();
        });
    }
};
