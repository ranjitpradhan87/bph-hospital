<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lab tests ordered for an admitted patient post their cost to the IPD register on their own.
 * The entry remembers its lab order, so cancelling the order removes it and staff cannot edit it by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ipd_entries', function (Blueprint $t) {
            $t->foreignId('lab_order_id')->nullable()->unique()->after('admission_id')->constrained('lab_orders')->nullOnDelete();
        });

        // A fresh install gets the column from the seeder, in its place after the others; an existing register gets it appended.
        if (DB::table('ipd_heads')->exists() && ! DB::table('ipd_heads')->where('code', 'lab')->exists()) {
            DB::table('ipd_heads')->insert(['code' => 'lab', 'name' => 'Lab tests', 'type' => 'charge', 'display_order' => 1 + (int) DB::table('ipd_heads')->max('display_order'),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('ipd_entries', function (Blueprint $t) {
            $t->dropConstrainedForeignId('lab_order_id');
        });
    }
};
