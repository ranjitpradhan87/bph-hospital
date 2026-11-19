<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use App\Services\PatientAccess;
use App\Services\RbacGuard;
use App\Support\Audit;
use App\Support\Codes;
use App\Support\Hospital;
use App\Support\Paging;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PatientController extends Controller
{
    private const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    private const DOC_TYPES = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    private const RECORD_TYPES = ['note', 'observation', 'document', 'allergy', 'history'];

    private const VISIBILITY = ['clinical', 'doctor_only', 'patient_visible'];

    private const BLOOD = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    // ---------- access scope ----------

    private static function doctorIdFor(User $user): ?int
    {
        return DB::table('doctors')->where('user_id', $user->id)->whereNull('deleted_at')->value('id');
    }

    /** A doctor without front-desk/admin rights only sees patients they have appointments with. */
    private static function scopedDoctor(User $user): ?int
    {
        return $user->can('patients.update') ? null : self::doctorIdFor($user);
    }

    private static function accessible(int $id, User $user): Patient
    {
        $patient = Patient::find($id);
        if (! $patient) {
            abort(404, 'Patient not found');
        }
        // Accounts without patients.read (i.e. patients themselves) can only ever reach their own record.
        if (! $user->can('patients.read') && $patient->user_id !== $user->id) {
            abort(404, 'Patient not found');
        }
        $doctorId = self::scopedDoctor($user);
        if ($doctorId && ! DB::table('appointments')->where('patient_id', $patient->id)->where('doctor_id', $doctorId)->exists()) {
            abort(404, 'Patient not found'); // don't reveal that the patient exists
        }

        return $patient;
    }

    // ---------- output ----------

    private static function age(?string $dob): ?int
    {
        return $dob === null ? null : Carbon::parse($dob)->diffInYears(Carbon::parse(Hospital::today()));
    }

    private static function out(Patient $p): array
    {
        return [
            'id' => $p->id, 'patient_code' => $p->patient_code, 'full_name' => $p->full_name, 'mobile' => $p->mobile, 'email' => $p->email,
            'date_of_birth' => $p->date_of_birth?->toDateString(), 'gender' => $p->gender, 'blood_group' => $p->blood_group,
            'details_pending' => $p->date_of_birth === null, 'password_pending' => PatientAccess::passwordPending($p->user),
            'address' => $p->address, 'city' => $p->city, 'state' => $p->state, 'pincode' => $p->pincode,
            'emergency_contact_name' => $p->emergency_contact_name, 'emergency_contact_phone' => $p->emergency_contact_phone,
            'has_login' => $p->user_id !== null, 'consent_at' => $p->consent_at?->toIso8601String(), 'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    // ---------- validation ----------

    private static function base(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today', 'after:-130 years'],
            'gender' => ['required', Rule::in(['male', 'female', 'other'])],
            'blood_group' => ['nullable', Rule::in(self::BLOOD)],
            'address' => ['nullable', 'string', 'max:500'], 'city' => ['nullable', 'string', 'max:80'], 'state' => ['nullable', 'string', 'max:80'],
            'pincode' => ['nullable', 'regex:/^\d{6}$/'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'],
        ];
    }

    private static function normalise(Request $request, array $phoneFields): void
    {
        $merge = [];
        foreach ($phoneFields as $f) {
            if ($request->filled($f)) {
                $merge[$f] = Phone::normalize((string) $request->input($f));
            }
        }
        if ($request->filled('email')) {
            $merge['email'] = strtolower(trim((string) $request->input('email')));
        }
        $request->merge($merge);
    }

    private const MESSAGES = ['mobile.regex' => 'Enter a valid 10-digit Indian mobile number', 'emergency_contact_phone.regex' => 'Enter a valid 10-digit Indian mobile number', 'pincode.regex' => 'PIN code must be 6 digits'];

    // ---------- patients ----------

    public function index(Request $request): JsonResponse
    {
        $request->validate(['search' => 'nullable|string|max:100']);
        $today = Hospital::today();
        $last = DB::table('appointments')->where('appointment_date', '<=', $today)->groupBy('patient_id')->selectRaw('patient_id, max(appointment_date) as last_visit');
        $q = Patient::query()->leftJoinSub($last, 'lv', 'lv.patient_id', '=', 'patients.id')->select('patients.*', 'lv.last_visit');
        if ($request->query('incomplete') === '1') {
            $q->whereNull('patients.date_of_birth'); // booked online; details still to be filled in
        }
        if ($doctorId = self::scopedDoctor($request->user())) {
            $q->whereIn('patients.id', DB::table('appointments')->where('doctor_id', $doctorId)->select('patient_id'));
        }
        if ($term = trim((string) $request->query('search'))) {
            $digits = preg_replace('/\D/', '', $term);
            $q->where(function ($w) use ($term, $digits) {
                $w->where('patients.full_name', 'like', "%$term%")->orWhere('patients.patient_code', 'like', "%$term%")->orWhere('patients.email', 'like', "%$term%");
                if (strlen($digits) >= 4) {
                    $w->orWhere('patients.mobile', 'like', '%'.substr($digits, -10).'%');
                }
            });
        }

        return Paging::respond($request, $q->orderByDesc('patients.created_at')->orderByDesc('patients.id'), fn ($p) => [
            'id' => $p->id, 'patient_code' => $p->patient_code, 'full_name' => $p->full_name, 'mobile' => $p->mobile, 'gender' => $p->gender,
            'age' => self::age($p->date_of_birth?->toDateString()), 'details_pending' => $p->date_of_birth === null, 'last_visit' => $p->last_visit, 'created_at' => $p->created_at?->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        self::normalise($request, ['mobile', 'emergency_contact_phone']);
        $data = $request->validate(self::base() + ['consent' => ['accepted'], 'allow_duplicate' => ['nullable', 'boolean']],
            self::MESSAGES + ['consent.accepted' => "Record the patient's consent before registering"]);
        if (empty($data['allow_duplicate'])) {
            $dup = Patient::where('mobile', $data['mobile'])->whereDate('date_of_birth', $data['date_of_birth'])->first();
            if ($dup) {
                abort(409, "{$dup->full_name} ({$dup->patient_code}) has the same mobile number and date of birth. Open that record instead, or confirm this is a different person.");
            }
        }
        unset($data['consent'], $data['allow_duplicate']);
        $patient = DB::transaction(function () use ($data, $request) {
            $patient = Patient::create($data + ['patient_code' => Codes::placeholder(), 'consent_at' => now(), 'registered_by_user_id' => $request->user()->id]);
            $patient->update(['patient_code' => Codes::patient($patient->id)]);
            Audit::record('create', 'patient', $patient->id);

            return $patient;
        });

        return response()->json(self::out($patient->refresh()), 201);
    }

    public function show(Request $request, int $patient): JsonResponse
    {
        return response()->json(self::out(self::accessible($patient, $request->user())));
    }

    public function update(Request $request, int $patient): JsonResponse
    {
        $actor = $request->user();
        $p = self::accessible($patient, $actor);
        $wasPending = $p->date_of_birth === null;
        self::normalise($request, ['mobile', 'emergency_contact_phone']);
        $data = $request->validate(self::base(), self::MESSAGES);
        $data += array_fill_keys(['email', 'blood_group', 'address', 'city', 'state', 'pincode', 'emergency_contact_name', 'emergency_contact_phone'], null);

        DB::transaction(function () use ($p, $data, $actor) {
            $p->fill($data);
            $changed = array_keys($p->getDirty());
            // Keep the patient's login in step with corrected contact details.
            if ($p->user_id && array_intersect(['email', 'mobile', 'full_name'], $changed)) {
                RbacGuard::ensureContactAvailable($p->email, $p->mobile, $p->user_id);
                $p->user->update(['email' => $p->email, 'mobile' => $p->mobile, 'full_name' => $p->full_name]);
            }
            $p->save();
            if ($changed) {
                // Field names only: personal data stays out of the audit log.
                Audit::record('update', 'patient', $p->id, ['fields' => $changed]);
            }
        });

        // Details completed for someone who booked online: text them the link to set their password.
        $sent = $wasPending && $p->refresh()->date_of_birth !== null && PatientAccess::sendSetupLink($p);

        return response()->json(self::out($p->refresh()) + ['password_link_sent' => $sent]);
    }

    /** Text (or text again) the link that lets a patient who booked online choose their own password. */
    public function sendPasswordLink(Request $request, int $patient): JsonResponse
    {
        $p = self::accessible($patient, $request->user());
        if (! $p->user_id) {
            abort(409, 'This patient has no online account yet');
        }
        PatientAccess::sendSetupLink($p) || abort(409, 'This patient has already set up their password');
        Audit::record('send_password_link', 'patient', $p->id);

        return response()->json(['sent' => true]);
    }

    public function appointments(Request $request, int $patient): JsonResponse
    {
        $p = self::accessible($patient, $request->user());
        $q = DB::table('appointments as a')->join('patients as pt', 'pt.id', '=', 'a.patient_id')
            ->join('doctors as d', 'd.id', '=', 'a.doctor_id')->join('users as u', 'u.id', '=', 'd.user_id')
            ->join('departments as dep', 'dep.id', '=', 'a.department_id')->where('a.patient_id', $p->id)
            ->select('a.*', 'pt.patient_code', 'pt.full_name as patient_name', 'u.full_name as doctor_name', 'dep.name as department_name');

        return Paging::respond($request, $q->orderByDesc('a.appointment_date')->orderByDesc('a.start_time'), fn ($a) => DoctorController::appointmentRow($a));
    }

    public function bills(Request $request, int $patient): JsonResponse
    {
        $p = self::accessible($patient, $request->user());
        $rows = DB::table('bills')->where('patient_id', $p->id)->orderByDesc('bill_date')->orderByDesc('id')->get();

        return response()->json($rows->map(fn ($b) => [
            'id' => $b->id, 'bill_number' => $b->bill_number, 'bill_date' => $b->bill_date,
            'total_amount' => DoctorController::money($b->total_amount), 'paid_amount' => DoctorController::money($b->paid_amount),
            'balance' => DoctorController::money($b->total_amount - $b->paid_amount), 'status' => $b->status,
        ]));
    }

    // ---------- patient self-service ----------

    private static function own(User $user): Patient
    {
        return Patient::where('user_id', $user->id)->first() ?? abort(404, 'No patient record is linked to this account');
    }

    public function portalProfile(Request $request): JsonResponse
    {
        return response()->json(self::out(self::own($request->user())));
    }

    public function updatePortalProfile(Request $request): JsonResponse
    {
        $p = self::own($request->user());
        self::normalise($request, ['emergency_contact_phone']);
        $rules = array_intersect_key(self::base(), array_flip(['blood_group', 'address', 'city', 'state', 'pincode', 'emergency_contact_name', 'emergency_contact_phone']));
        $data = $request->validate($rules, self::MESSAGES);
        $data += array_fill_keys(array_keys($rules), null);
        $p->fill($data);
        $changed = array_keys($p->getDirty());
        $p->save();
        if ($changed) {
            Audit::record('update', 'patient', $p->id, ['fields' => $changed, 'via' => 'portal']);
        }

        return response()->json(self::out($p->refresh()));
    }

    // ---------- medical records ----------

    private static function isDoctor(User $u): bool
    {
        return in_array('doctor', $u->roleCodes(), true);
    }

    private static function visible(MedicalRecord $r, User $u): bool
    {
        return $r->visibility !== 'doctor_only' || self::isDoctor($u) || $r->recorded_by_user_id === $u->id;
    }

    private static function recordOut(MedicalRecord $r): array
    {
        return [
            'id' => $r->id, 'patient_id' => $r->patient_id, 'record_type' => $r->record_type, 'title' => $r->title,
            'content' => $r->content_encrypted, 'data' => $r->data, 'visibility' => $r->visibility, 'file_name' => $r->file_name,
            'mime_type' => $r->mime_type, 'file_size' => $r->file_size,
            'recorded_by' => $r->recorded_by_user_id ? DB::table('users')->where('id', $r->recorded_by_user_id)->value('full_name') : null,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    public function records(Request $request, int $patient): JsonResponse
    {
        $user = $request->user();
        $p = self::accessible($patient, $user);
        $records = MedicalRecord::where('patient_id', $p->id)->orderByDesc('created_at')->orderByDesc('id')->get()->filter(fn ($r) => self::visible($r, $user))->values();
        Audit::record('read', 'medical_records', $p->id, ['count' => $records->count()]);

        return response()->json($records->map(fn ($r) => self::recordOut($r)));
    }

    private static function checkCreateRights(User $user, string $type, string $visibility): void
    {
        if ($type === 'observation') {
            if (! ($user->can('observations.create') || $user->can('medical_records.create'))) {
                abort(403, 'You do not have permission to perform this action');
            }
        } elseif (! $user->can('medical_records.create')) {
            abort(403, 'You can record observations only');
        }
        if ($visibility === 'doctor_only' && ! self::isDoctor($user)) {
            abort(403, 'Only doctors can write doctor-only notes');
        }
    }

    public function addRecord(Request $request, int $patient): JsonResponse
    {
        $user = $request->user();
        $p = self::accessible($patient, $user);
        $data = $request->validate([
            'record_type' => ['required', Rule::in(self::RECORD_TYPES)],
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'content' => ['nullable', 'string', 'max:10000'],
            'data' => ['nullable', 'array'],
            'visibility' => ['nullable', Rule::in(self::VISIBILITY)],
        ]);
        $data['visibility'] ??= 'clinical';
        if ($data['record_type'] === 'document') {
            abort(422, 'Upload documents with the file upload endpoint');
        }
        if ($data['record_type'] === 'observation') {
            $d = $data['data'] ?? [];
            if (! $d) {
                abort(422, 'Observations need at least one measurement');
            }
            foreach ($d as $k => $v) {
                if (count($d) > 20 || strlen((string) $k) > 40 || strlen(is_scalar($v) ? (string) $v : json_encode($v)) > 60) {
                    abort(422, 'Too many or too long measurements');
                }
            }
        } elseif (empty($data['content'])) {
            abort(422, "Write the note's content");
        }
        self::checkCreateRights($user, $data['record_type'], $data['visibility']);

        $record = MedicalRecord::create([
            'patient_id' => $p->id, 'record_type' => $data['record_type'], 'title' => trim($data['title']), 'content_encrypted' => $data['content'] ?? null,
            'data' => $data['data'] ?? null, 'visibility' => $data['visibility'], 'recorded_by_user_id' => $user->id,
        ]);
        Audit::record('create', 'medical_record', $record->id, ['patient_id' => $p->id, 'type' => $data['record_type']]);

        return response()->json(self::recordOut($record->refresh()), 201);
    }

    private static function sniff(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, '%PDF-') => 'pdf',
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($head, "\xff\xd8\xff") => 'jpg',
            substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    public function addDocument(Request $request, int $patient): JsonResponse
    {
        $user = $request->user();
        $p = self::accessible($patient, $user);
        $visibility = $request->input('visibility', 'clinical');
        if (! in_array($visibility, self::VISIBILITY, true)) {
            abort(422, 'Unknown visibility');
        }
        self::checkCreateRights($user, 'document', $visibility);
        $title = trim((string) $request->input('title', ''));
        if (mb_strlen($title) < 2 || mb_strlen($title) > 200) {
            abort(400, 'Give the document a title (2–200 characters)');
        }
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            abort(400, 'No file was uploaded');
        }
        if ($file->getSize() > self::MAX_DOCUMENT_BYTES) {
            abort(400, 'Documents must be 10 MB or smaller');
        }
        $data = file_get_contents($file->getRealPath());
        $ext = self::sniff(substr((string) $data, 0, 16));
        if ($ext === null) {
            abort(400, 'Only PDF, PNG, JPEG or WebP files are allowed');
        }
        $stored = bin2hex(random_bytes(16)).".$ext";
        Storage::disk('local')->put("records/$stored", $data);
        $record = MedicalRecord::create([
            'patient_id' => $p->id, 'record_type' => 'document', 'title' => $title, 'visibility' => $visibility,
            'file_path' => "records/$stored", 'file_name' => substr(basename((string) $file->getClientOriginalName()) ?: "document.$ext", 0, 200),
            'mime_type' => self::DOC_TYPES[$ext], 'file_size' => strlen($data), 'recorded_by_user_id' => $user->id,
        ]);
        Audit::record('upload', 'medical_record', $record->id, ['patient_id' => $p->id, 'size' => strlen($data), 'type' => $ext]);

        return response()->json(self::recordOut($record->refresh()), 201);
    }

    public function download(Request $request, int $record): BinaryFileResponse
    {
        $user = $request->user();
        $r = MedicalRecord::find($record);
        if (! $r || ! $r->file_path) {
            abort(404, 'Document not found');
        }
        self::accessible($r->patient_id, $user);
        if (! self::visible($r, $user) || ! Storage::disk('local')->exists($r->file_path) || ! str_starts_with($r->file_path, 'records/')) {
            abort(404, 'Document not found');
        }
        Audit::record('download', 'medical_record', $r->id, ['patient_id' => $r->patient_id]);

        return response()->download(Storage::disk('local')->path($r->file_path), $r->file_name ?: basename($r->file_path), [
            'Content-Type' => $r->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox',
        ]);
    }
}
