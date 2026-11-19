<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Every hospital setting: group, default, whether the public site may read it, and how it validates.
 * The settings API validates each key against this registry so the JSON column never holds a value
 * the application can't use. Mirrors the original FastAPI registry.
 */
final class SettingsRegistry
{
    public const ICONS = [
        'accessibility', 'ambulance', 'baby', 'bed-double', 'bone', 'brain', 'credit-card', 'heart-pulse',
        'microscope', 'pill', 'scan', 'scan-face', 'scissors', 'siren', 'smile', 'stethoscope', 'venus', 'video',
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $specs = null;

    public static function imageUrlRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }
            if (! (is_string($value) && (str_starts_with($value, '/api/v1/media/') || preg_match('~^https://[^\s"\'<>]+$~', $value)))) {
                $fail('Use an uploaded image or an https:// URL');
            }
        };
    }

    private static function spec(string $key, string $group, mixed $default, array $rules, bool $public = false, bool $editable = true, array $extra = []): array
    {
        return ['key' => $key, 'group' => $group, 'default' => $default, 'rules' => $rules, 'public' => $public, 'editable' => $editable] + $extra;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        if (self::$specs !== null) {
            return self::$specs;
        }
        $text = fn (int $max) => ['nullable', 'string', "max:$max"];
        $phone = ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\- ]*$/'];
        $img = ['nullable', 'string', self::imageUrlRule()];
        $int = fn (int $min, int $max) => ['required', 'integer', "min:$min", "max:$max"];
        $contentItem = [
            '' => ['present', 'array', 'max:12'],
            '*.icon' => ['required', Rule::in(self::ICONS)],
            '*.title' => ['required', 'string', 'min:2', 'max:80'],
            '*.description' => ['nullable', 'string', 'max:300'],
        ];
        $itemShape = ['*' => ['icon', 'title', 'description']];

        $list = [
            self::spec('hospital.name', 'general', config('app.name'), ['' => ['required', 'string', 'min:2', 'max:150']], true),
            self::spec('hospital.tagline', 'general', 'Compassionate care, modern medicine', ['' => $text(150)], true),
            self::spec('hospital.logo_url', 'general', null, ['' => $img], true),
            self::spec('hospital.address', 'general', ['line1' => '', 'line2' => '', 'city' => '', 'state' => '', 'pincode' => '', 'country' => 'India'], [
                '' => ['array'],
                'line1' => $text(200), 'line2' => $text(200), 'city' => $text(80), 'state' => $text(80), 'country' => $text(80),
                'pincode' => ['nullable', 'regex:/^(\d{6})?$/'],
            ], true, true, ['shape' => ['line1', 'line2', 'city', 'state', 'pincode', 'country'], 'messages' => ['pincode.regex' => 'PIN code must be 6 digits']]),
            self::spec('hospital.phone', 'general', '', ['' => $phone], true, true, ['messages' => ['regex' => 'Use digits, spaces, +, - or brackets only']]),
            self::spec('hospital.emergency_phone', 'general', '', ['' => $phone], true, true, ['messages' => ['regex' => 'Use digits, spaces, +, - or brackets only']]),
            self::spec('hospital.email', 'general', '', ['' => ['nullable', 'email:rfc', 'max:255']], true, true, ['lower' => true]),
            self::spec('hospital.map_embed_url', 'general', '', ['' => ['nullable', 'string', 'max:1000', function ($a, $v, $fail) {
                if ($v && ! str_starts_with($v, 'https://www.google.com/maps/embed') && ! str_starts_with($v, 'https://maps.google.com/')) {
                    $fail('Paste the embed link from Google Maps (Share → Embed a map)');
                }
            }]], true),
            self::spec('hospital.working_days', 'general', [0, 1, 2, 3, 4, 5], [
                '' => ['required', 'array', 'min:1', 'max:7', function ($a, $v, $fail) {
                    if (count(array_unique($v)) !== count($v)) {
                        $fail('Each day can appear only once');
                    }
                }],
                '*' => ['integer', 'min:0', 'max:6'],
            ], true, true, ['sort' => true]),
            self::spec('hospital.working_hours', 'general', ['start' => '09:00', 'end' => '18:00'], [
                '' => ['required', 'array'],
                'start' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
                'end' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/', function ($a, $v, $fail) {
                    // compared after both fields are individually valid, see validate()
                }],
            ], true, true, ['shape' => ['start', 'end'], 'order' => ['start', 'end']]),
            self::spec('hospital.timezone', 'general', config('app.hospital_timezone', 'Asia/Kolkata'), ['' => ['string']], false, false),
            self::spec('booking.default_slot_duration_min', 'booking', 15, ['' => ['required', Rule::in([10, 15, 20, 30, 60])]]),
            self::spec('booking.advance_booking_days', 'booking', 30, ['' => $int(1, 180)], true),
            self::spec('booking.min_lead_minutes', 'booking', 30, ['' => $int(0, 1440)]),
            self::spec('booking.cancellation_cutoff_hours', 'booking', 4, ['' => $int(0, 168)], true),
            self::spec('booking.reschedule_cutoff_hours', 'booking', 4, ['' => $int(0, 168)], true),
            self::spec('booking.max_reschedules', 'booking', 2, ['' => $int(0, 10)], true),
            self::spec('booking.auto_confirm', 'booking', true, ['' => ['required', 'boolean']]),
            self::spec('booking.advance_required', 'booking', true, ['' => ['required', 'boolean']], true),
            self::spec('booking.advance_amount', 'booking', 300, ['' => $int(0, 100000)], true),
            self::spec('booking.payment_hold_minutes', 'booking', 15, ['' => $int(5, 60)]),
            self::spec('billing.currency', 'billing', 'INR', ['' => ['required', Rule::in(['INR'])]], true, false),
            self::spec('billing.tax_enabled', 'billing', false, ['' => ['required', 'boolean']]),
            self::spec('billing.default_tax_pct', 'billing', 0, ['' => ['required', 'numeric', 'min:0', 'max:28']]),
            self::spec('billing.invoice_prefix', 'billing', 'INV', ['' => ['required', 'regex:/^[A-Za-z]{2,6}$/']], false, true, ['upper' => true, 'messages' => ['regex' => 'Use 2 to 6 letters']]),
            self::spec('notifications.email_enabled', 'notifications', true, ['' => ['required', 'boolean']]),
            self::spec('notifications.sms_enabled', 'notifications', true, ['' => ['required', 'boolean']]),
            self::spec('notifications.whatsapp_enabled', 'notifications', false, ['' => ['required', 'boolean']]),
            self::spec('notifications.reminder_hours_before', 'notifications', 24, ['' => $int(1, 72)]),
            self::spec('registration.patient_self_signup', 'registration', true, ['' => ['required', 'boolean']], true),
            self::spec('registration.require_email_verification', 'registration', false, ['' => ['required', 'boolean']]),
            self::spec('hospital.hero_image_url', 'website', null, ['' => $img], true),
            self::spec('hospital.about_image_url', 'website', null, ['' => $img], true),
            self::spec('content.hero', 'website', [
                'title' => 'Trusted care for every stage of life',
                'subtitle' => 'Book a consultation with our specialists online, see live availability, and manage your visits from one place.',
            ], ['' => ['array'], 'title' => $text(120), 'subtitle' => $text(300)], true, true, ['shape' => ['title', 'subtitle']]),
            self::spec('content.about', 'website', [
                'summary' => 'A multi-speciality hospital bringing experienced doctors, modern diagnostics and attentive nursing together under one roof.',
                'mission' => 'To provide safe, evidence-based and affordable care with dignity and compassion.',
                'vision' => 'To be the hospital our community trusts first for its health.',
                'values' => ['Patient safety first', 'Respect and empathy', 'Clinical excellence', 'Transparency'],
            ], ['' => ['array'], 'summary' => $text(600), 'mission' => $text(400), 'vision' => $text(400),
                'values' => ['nullable', 'array', 'max:8'], 'values.*' => ['string', 'max:60']], true, true,
                ['shape' => ['summary', 'mission', 'vision', 'values']]),
            self::spec('content.services', 'website', [
                ['icon' => 'stethoscope', 'title' => 'Outpatient consultations', 'description' => 'Scheduled visits with specialists across departments, with online booking.'],
                ['icon' => 'siren', 'title' => 'Emergency care', 'description' => 'Round-the-clock emergency assessment and stabilisation.'],
                ['icon' => 'microscope', 'title' => 'Laboratory diagnostics', 'description' => 'Blood tests, pathology and microbiology with reports shared digitally.'],
                ['icon' => 'scan', 'title' => 'Radiology & imaging', 'description' => 'X-ray, ultrasound and other imaging, read by qualified radiologists.'],
                ['icon' => 'pill', 'title' => 'Pharmacy', 'description' => 'In-house pharmacy for prescriptions issued during your visit.'],
                ['icon' => 'video', 'title' => 'Teleconsultation', 'description' => "Online consultations for follow-ups and advice when travel isn't needed."],
            ], $contentItem, true, true, ['shape' => $itemShape]),
            self::spec('content.facilities', 'website', [
                ['icon' => 'bed-double', 'title' => 'Inpatient wards', 'description' => 'Clean, well-ventilated general and private rooms.'],
                ['icon' => 'heart-pulse', 'title' => 'Intensive care unit', 'description' => 'Continuous monitoring for critically ill patients.'],
                ['icon' => 'scissors', 'title' => 'Operation theatres', 'description' => 'Modular theatres equipped for planned and emergency surgery.'],
                ['icon' => 'ambulance', 'title' => 'Ambulance service', 'description' => 'Equipped ambulances for emergency transfers.'],
                ['icon' => 'accessibility', 'title' => 'Accessible campus', 'description' => 'Ramps, wheelchairs and assistance at the help desk.'],
                ['icon' => 'credit-card', 'title' => 'Cashless & digital payments', 'description' => 'UPI, cards and bank transfers accepted at billing.'],
            ], $contentItem, true, true, ['shape' => $itemShape]),
            // Only real, consented patient testimonials belong here, so none are seeded.
            self::spec('content.testimonials', 'website', [], [
                '' => ['present', 'array', 'max:12'],
                '*.name' => ['required', 'string', 'min:2', 'max:80'],
                '*.quote' => ['required', 'string', 'min:10', 'max:400'],
                '*.context' => ['nullable', 'string', 'max:80'],
            ], true, true, ['shape' => ['*' => ['name', 'quote', 'context']]]),
        ];

        self::$specs = [];
        foreach ($list as $s) {
            self::$specs[$s['key']] = $s;
        }

        return self::$specs;
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Current stored value for a key, or its default. */
    public static function value(string $key, mixed $fallback = null): mixed
    {
        $row = DB::table('hospital_settings')->where('key', $key)->first();
        if ($row !== null && $row->value !== null) {
            return json_decode($row->value, true);
        }
        $spec = self::find($key);

        return $spec ? $spec['default'] : $fallback;
    }

    /**
     * Validate and normalise a value. Returns [normalised, errors]; errors are FastAPI-style
     * entries with loc = [..., field path] relative to the setting.
     *
     * @return array{0: mixed, 1: list<array{loc: list<string|int>, msg: string, type: string}>}
     */
    public static function validate(string $key, mixed $value): array
    {
        $spec = self::find($key);
        $value = self::trimStrings($value);
        $errors = [];

        // Unknown fields are rejected, like the original strict models.
        $shape = $spec['shape'] ?? null;
        if ($shape !== null && is_array($value)) {
            if (isset($shape['*'])) {
                foreach ($value as $i => $item) {
                    foreach (is_array($item) ? array_diff(array_keys($item), $shape['*']) : [] as $extra) {
                        $errors[] = ['loc' => [$i, $extra], 'msg' => 'Extra inputs are not permitted', 'type' => 'extra_forbidden'];
                    }
                }
            } elseif (! array_is_list($value)) {
                foreach (array_diff(array_keys($value), $shape) as $extra) {
                    $errors[] = ['loc' => [$extra], 'msg' => 'Extra inputs are not permitted', 'type' => 'extra_forbidden'];
                }
            }
        }

        $rules = [];
        foreach ($spec['rules'] as $suffix => $r) {
            $rules[$suffix === '' ? 'v' : "v.$suffix"] = $r;
        }
        $messages = [];
        foreach ($spec['messages'] ?? [] as $k => $m) {
            $messages["v.$k"] = $m;
            $messages[$k] = $m;
        }
        $v = Validator::make(['v' => $value], $rules, $messages);
        foreach ($v->errors()->messages() as $field => $msgs) {
            $loc = array_map(fn ($p) => ctype_digit($p) ? (int) $p : $p, array_slice(explode('.', $field), 1));
            $errors[] = ['loc' => $loc, 'msg' => str_replace('v.', '', $msgs[0]), 'type' => 'value_error'];
        }
        if (isset($spec['order']) && ! $errors && $value[$spec['order'][1]] <= $value[$spec['order'][0]]) {
            $errors[] = ['loc' => [], 'msg' => 'Closing time must be after opening time', 'type' => 'value_error'];
        }
        if ($errors) {
            return [null, $errors];
        }

        return [self::normalise($spec, $value), []];
    }

    private static function normalise(array $spec, mixed $value): mixed
    {
        $d = $spec['default'];
        if (isset($spec['shape']) && ! isset($spec['shape']['*']) && is_array($value)) {
            // Fill omitted or blank fields from the defaults, keeping the declared field order.
            $out = [];
            foreach ($spec['shape'] as $f) {
                $given = $value[$f] ?? null;
                $out[$f] = $given ?? (is_array($d[$f] ?? null) ? [] : ($f === 'country' ? ($d[$f] ?? '') : ''));
            }
            return $out;
        }
        if (isset($spec['shape']['*']) && is_array($value)) {
            return array_values(array_map(function ($item) use ($spec) {
                $o = [];
                foreach ($spec['shape']['*'] as $f) {
                    $o[$f] = $item[$f] ?? ($f === 'icon' || $f === 'title' || $f === 'name' || $f === 'quote' ? '' : '');
                }
                return $o;
            }, $value));
        }
        if (! empty($spec['sort']) && is_array($value)) {
            $value = array_map('intval', $value);
            sort($value);
            return array_values($value);
        }
        if (is_bool($d)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if (is_int($d)) {
            return (int) $value;
        }
        if ((is_string($value) || $value === null) && ! is_float($d)) {
            $str = $value ?? (is_string($d) ? '' : null);
            if (is_string($str) && ! empty($spec['lower'])) {
                $str = strtolower($str);
            }
            if (is_string($str) && ! empty($spec['upper'])) {
                $str = strtoupper($str);
            }
            if (is_string($str) && $str === '' && $d === null) {
                return null; // optional image URLs
            }
            return $str;
        }
        if (is_bool($d)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if (is_int($d)) {
            return (int) $value;
        }
        if (is_numeric($value) && is_numeric($d)) {
            return $value + 0;
        }

        return $value;
    }

    private static function trimStrings(mixed $v): mixed
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (is_array($v)) {
            return array_map(fn ($x) => self::trimStrings($x), $v);
        }

        return $v;
    }
}
