<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Doctors typed into the register as free text ("DR A ROUT, DR K PARIJA") are matched to the registered doctors
 * and linked. Names that match nobody stay as typed text (visiting doctors), exactly as before.
 */
return new class extends Migration
{
    private static function key(string $name): string
    {
        $n = mb_strtolower($name);
        $n = preg_replace('/\b(dr|doctor|prof|mr|mrs|ms)\b\.?/u', ' ', $n);

        return preg_replace('/[^a-z0-9]+/', '', $n);
    }

    public function up(): void
    {
        $doctors = DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->whereNull('d.deleted_at')->get(['d.id', 'u.full_name'])
            ->map(fn ($d) => ['id' => $d->id, 'key' => self::key($d->full_name)])->filter(fn ($d) => strlen($d['key']) >= 3)->values();
        $match = function (string $name) use ($doctors) {
            $k = self::key($name);
            if (strlen($k) < 3) {
                return null;
            }
            $hits = $doctors->filter(fn ($d) => $d['key'] === $k)->values();
            if ($hits->isEmpty()) { // e.g. "MEERA NAIR" typed for "Dr Meera Nair": one name contains the other
                $hits = $doctors->filter(fn ($d) => str_contains($d['key'], $k) || str_contains($k, $d['key']))->values();
            }

            return $hits->count() === 1 ? $hits[0]['id'] : null; // ambiguous names stay as typed
        };

        foreach (DB::table('ipd_admissions')->get(['id', 'doctors', 'anaesthetist', 'anaesthetist_id']) as $a) {
            $left = [];
            foreach (array_filter(array_map('trim', explode(',', (string) $a->doctors))) as $name) {
                $id = $match($name);
                if ($id) {
                    DB::table('ipd_admission_doctors')->insertOrIgnore(['admission_id' => $a->id, 'doctor_id' => $id]);
                } else {
                    $left[] = $name;
                }
            }
            $update = ['doctors' => $left ? implode(', ', $left) : null];
            if (! $a->anaesthetist_id && filled($a->anaesthetist) && ($id = $match($a->anaesthetist))) {
                $update['anaesthetist_id'] = $id;
                $update['anaesthetist'] = DB::table('doctors as d')->join('users as u', 'u.id', '=', 'd.user_id')->where('d.id', $id)->value('u.full_name');
            }
            DB::table('ipd_admissions')->where('id', $a->id)->update($update);
        }
    }

    public function down(): void
    {
    }
};
