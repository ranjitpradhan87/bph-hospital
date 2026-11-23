<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The hospital is called Bishnupriya Sevasadan. Only the old default name is replaced; a name someone typed in Settings is left alone. */
return new class extends Migration
{
    private function rename(string $from, string $to): void
    {
        $row = DB::table('hospital_settings')->where('key', 'hospital.name')->first();
        if ($row && json_decode((string) $row->value, true) === $from) {
            DB::table('hospital_settings')->where('id', $row->id)->update(['value' => json_encode($to), 'updated_at' => now()]);
        }
    }

    public function up(): void
    {
        $this->rename('BPH Hospital', 'Bishnupriya Sevasadan');
    }

    public function down(): void
    {
        $this->rename('Bishnupriya Sevasadan', 'BPH Hospital');
    }
};
