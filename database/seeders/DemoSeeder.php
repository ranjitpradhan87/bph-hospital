<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Doctor;
use App\Models\Role;
use App\Models\User;
use App\Support\Codes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fictional demo data for LOCAL DEVELOPMENT ONLY (php artisan db:seed --class=DemoSeeder).
 * Never run this in production: the doctors, phone numbers and registration numbers are made up.
 */
class DemoSeeder extends Seeder
{
    /** A few fictional in-patient rows in the current month, shaped like the hospital's paper register. */
    private function ipdDemo(): void
    {
        if (DB::table('ipd_admissions')->exists()) {
            return;
        }
        $day = fn (int $d) => now()->startOfMonth()->addDays($d - 1)->toDateString();
        $heads = DB::table('ipd_heads')->pluck('id', 'code');
        $rows = [
            [1, 'Demo Patient A', 'G-1', 'LSCS', [1, 2], 'discharged', [['cash', 6, 19000], ['upi', 1, 9500], ['doctor_fee', 1, 3500], ['anaesthesia', 7, 2000], ['xray', 3, 700]]],
            [1, 'Demo Patient B', 'P-6', 'ORTHO', [3], 'admitted', [['cash', 6, 5000], ['doctor_fee', 7, 6500], ['extra_expenses', 6, 2000], ['usg', 3, 700]]],
            [2, 'Demo Patient C', 'D-3', 'APPENDIX', [1], 'discharged', [['cash', 6, 20500], ['upi', 7, 12500], ['doctor_fee', 7, 6000], ['implant', 7, 4000], ['ecg', 3, 300]]],
            [3, 'Demo Patient D', 'G-2', 'NVD', [2], 'discharged', [['card', 8, 7000], ['doctor_fee', 8, 2500]]],
            [4, 'Demo Patient E', 'P-3', 'PHYMOSIS', [3], 'admitted', [['upi', 9, 8000]]],
        ];
        foreach ($rows as [$d, $name, $bed, $case, $docs, $status, $entries]) {
            $patientId = DB::table('patients')->insertGetId(['patient_code' => 'TMP-'.bin2hex(random_bytes(8)), 'full_name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('patients')->where('id', $patientId)->update(['patient_code' => \App\Models\Patient::codeFor($patientId)]);
            $id = DB::table('ipd_admissions')->insertGetId(['admission_date' => $day($d), 'patient_id' => $patientId, 'patient_name' => $name, 'bed' => $bed, 'case_title' => $case,
                'status' => $status, 'discharge_date' => $status === 'discharged' ? $day($d + 3) : null, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($docs as $n) { // the registered demo doctors, so the register links to real doctor records
                $doctorId = DB::table('doctors as dr')->join('users as u', 'u.id', '=', 'dr.user_id')->where('u.email', 'demo.doctor'.$n.'@example.com')->value('dr.id');
                $doctorId && DB::table('ipd_admission_doctors')->insertOrIgnore(['admission_id' => $id, 'doctor_id' => $doctorId]);
            }
            foreach ($entries as [$code, $when, $amount]) {
                DB::table('ipd_entries')->insert(['admission_id' => $id, 'head_id' => $heads[$code], 'entry_date' => $day($when), 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function run(): void
    {
        $this->call(DatabaseSeeder::class);

        $departments = [
            ['Cardiology', 'heart-pulse', 'Heart and vascular care, from check-ups to long-term management.'],
            ['Paediatrics', 'baby', 'Care for babies, children and teenagers.'],
            ['Orthopaedics', 'bone', 'Bones, joints and sports injuries.'],
            ['Neurology', 'brain', 'Disorders of the brain and nervous system.'],
        ];

        $deptIds = [];
        foreach ($departments as $i => [$name, $icon, $short]) {
            $deptIds[$name] = Department::firstOrCreate(['slug' => strtolower($name)], ['name' => $name, 'icon' => $icon, 'short_description' => $short, 'display_order' => $i, 'is_active' => true])->id;
        }

        $doctors = [
            ['Dr Meera Nair', 'Cardiology', 'Cardiologist', 'MBBS, MD, DM (Cardiology)', 14, 800, true, '9300000001'],
            ['Dr Arjun Rao', 'Paediatrics', 'Paediatrician', 'MBBS, DCH', 9, 500, true, '9300000002'],
            ['Dr Kavita Menon', 'Orthopaedics', 'Orthopaedic surgeon', 'MBBS, MS (Ortho)', 12, 700, false, '9300000003'],
            ['Dr Sanjay Iyer', 'Neurology', 'Neurologist', 'MBBS, MD, DM (Neurology)', 16, 900, false, '9300000004'],
        ];
        $role = Role::where('code', 'doctor')->first();
        foreach ($doctors as $i => [$name, $dept, $spec, $qual, $years, $fee, $featured, $mobile]) {
            $email = 'demo.doctor'.($i + 1).'@example.com';
            if (User::where('email', $email)->exists()) {
                continue;
            }
            $user = User::create(['email' => $email, 'mobile' => $mobile, 'full_name' => $name, 'password' => 'DemoDoctor123']);
            $user->roles()->attach($role->id);
            $doctor = Doctor::create(['doctor_code' => Codes::placeholder(), 'user_id' => $user->id, 'qualification' => $qual, 'specialization' => $spec,
                'experience_years' => $years, 'registration_number' => 'DEMO-'.(1000 + $i), 'consultation_fee' => $fee, 'is_featured' => $featured,
                'languages' => 'English, Hindi', 'bio' => "$name is a fictional demo doctor used to preview the website.", 'status' => 'active']);
            $doctor->update(['doctor_code' => Codes::doctor($doctor->id)]);
            $doctor->departments()->attach($deptIds[$dept], ['is_primary' => true]);

            for ($d = 0; $d < 14; $d++) {
                $date = now()->addDays($d)->toDateString();
                if (date('N', strtotime($date)) == 7) {
                    continue;
                }
                for ($t = 9 * 60; $t < 13 * 60; $t += 15) {
                    DB::table('appointment_slots')->insertOrIgnore([
                        'doctor_id' => $doctor->id, 'department_id' => $deptIds[$dept], 'slot_date' => $date, 'start_time' => sprintf('%02d:%02d:00', intdiv($t, 60), $t % 60),
                        'end_time' => sprintf('%02d:%02d:00', intdiv($t + 15, 60), ($t + 15) % 60), 'capacity' => 1, 'booked_count' => 0, 'consultation_fee' => $fee,
                        'status' => 'available', 'is_published' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            foreach ([0, 1, 2, 3, 4, 5] as $dow) {
                DB::table('doctor_schedules')->insert(['doctor_id' => $doctor->id, 'department_id' => $deptIds[$dept], 'day_of_week' => $dow, 'start_time' => '09:00:00',
                    'end_time' => '13:00:00', 'effective_from' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->ipdDemo(); // after the doctors exist, so admissions link to them
    }
}
