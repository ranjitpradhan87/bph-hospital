<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Support\Audit;
use App\Support\SettingsRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SettingsController extends Controller
{
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    private const IMAGE_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    /** @return list<array<string, mixed>> */
    public static function listSettings(): array
    {
        $rows = DB::table('hospital_settings')->get()->keyBy('key');
        $out = [];
        foreach (SettingsRegistry::all() as $spec) {
            $row = $rows->get($spec['key']);
            $out[] = [
                'key' => $spec['key'], 'group' => $spec['group'],
                'value' => $row && $row->value !== null ? json_decode($row->value, true) : $spec['default'],
                'public' => $spec['public'], 'editable' => $spec['editable'],
                'updated_at' => $row ? \Illuminate\Support\Carbon::parse($row->updated_at)->toIso8601String() : null,
            ];
        }

        return $out;
    }

    public function index(): JsonResponse
    {
        return response()->json(self::listSettings());
    }

    public function update(Request $request): JsonResponse
    {
        $values = $request->input('values');
        if (! is_array($values) || $values === []) {
            return response()->json(['detail' => [['loc' => ['body', 'values'], 'msg' => 'Provide at least one setting', 'type' => 'value_error']]], 422);
        }
        $errors = [];
        $clean = [];
        foreach ($values as $key => $value) {
            $spec = SettingsRegistry::find((string) $key);
            if ($spec === null || ! $spec['editable']) {
                $errors[] = ['loc' => ['values', $key], 'msg' => "This setting can't be changed", 'type' => 'read_only'];

                continue;
            }
            [$normalised, $errs] = SettingsRegistry::validate($key, $value);
            foreach ($errs as $e) {
                $errors[] = ['loc' => ['values', $key, ...$e['loc']], 'msg' => $e['msg'], 'type' => $e['type']];
            }
            if (! $errs) {
                $clean[$key] = $normalised;
            }
        }
        if ($errors) {
            return response()->json(['detail' => $errors], 422);
        }

        $changes = [];
        DB::transaction(function () use ($clean, $request, &$changes) {
            foreach ($clean as $key => $value) {
                $spec = SettingsRegistry::find($key);
                $row = DB::table('hospital_settings')->where('key', $key)->first();
                $before = $row && $row->value !== null ? json_decode($row->value, true) : null;
                if ($row && $before === $value) {
                    continue;
                }
                $changes[$key] = ['before' => $before, 'after' => $value];
                $payload = ['value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_by_user_id' => $request->user()->id, 'updated_at' => now()];
                if ($row) {
                    DB::table('hospital_settings')->where('key', $key)->update($payload);
                } else {
                    DB::table('hospital_settings')->insert($payload + ['key' => $key, 'group' => $spec['group'], 'is_public' => $spec['public'], 'created_at' => now()]);
                }
            }
            if ($changes) {
                Audit::record('update', 'hospital_settings', null, $changes);
            }
        });

        return response()->json(self::listSettings());
    }

    // ---------- holidays ----------

    private static function holidayOut(Holiday $h): array
    {
        return ['id' => $h->id, 'holiday_date' => $h->holiday_date->toDateString(), 'name' => $h->name];
    }

    public function holidays(Request $request): JsonResponse
    {
        $request->validate(['year' => 'nullable|integer|min:2000|max:2100']);
        $q = Holiday::orderBy('holiday_date');
        if ($year = $request->query('year')) {
            $q->whereYear('holiday_date', (int) $year);
        }

        return response()->json($q->get()->map(fn ($h) => self::holidayOut($h)));
    }

    public function addHoliday(Request $request): JsonResponse
    {
        $data = $request->validate(['holiday_date' => ['required', 'date_format:Y-m-d'], 'name' => ['required', 'string', 'min:2', 'max:120']]);
        if (Holiday::where('holiday_date', $data['holiday_date'])->exists()) {
            abort(409, 'A holiday already exists on that date');
        }
        $h = Holiday::create(['holiday_date' => $data['holiday_date'], 'name' => trim($data['name'])]);
        Audit::record('create', 'holiday', $h->id, ['date' => $data['holiday_date'], 'name' => $h->name]);

        return response()->json(self::holidayOut($h), 201);
    }

    public function deleteHoliday(Holiday $holiday): JsonResponse
    {
        Audit::record('delete', 'holiday', $holiday->id, ['date' => $holiday->holiday_date->toDateString(), 'name' => $holiday->name]);
        $holiday->delete();

        return response()->json(null, 204);
    }

    // ---------- public image uploads ----------

    private static function sniff(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($head, "\xff\xd8\xff") => 'jpg',
            substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    /** Images are identified by magic bytes (never the client's name or type) and stored under a random name. SVG is refused. */
    public function uploadImage(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            abort(400, 'No file was uploaded');
        }
        if ($file->getSize() > self::MAX_IMAGE_BYTES) {
            abort(400, 'Image must be 5 MB or smaller');
        }
        $data = file_get_contents($file->getRealPath());
        if ($data === false || $data === '') {
            abort(400, 'The file is empty');
        }
        $ext = self::sniff(substr($data, 0, 16));
        if ($ext === null) {
            abort(400, 'Only PNG, JPEG or WebP images are allowed');
        }
        $name = bin2hex(random_bytes(16)).".$ext";
        Storage::disk('local')->put("media/$name", $data);

        return response()->json(['url' => "/api/v1/media/$name", 'content_type' => self::IMAGE_TYPES[$ext], 'size' => strlen($data)], 201);
    }

    public function media(string $name): BinaryFileResponse
    {
        if (! preg_match('/^[0-9a-f]{32}\.(png|jpg|webp)$/', $name) || ! Storage::disk('local')->exists("media/$name")) {
            abort(404, 'Not found');
        }
        $ext = substr($name, 33);

        // Names are random and never reused, so the file can be cached forever.
        return response()->file(Storage::disk('local')->path("media/$name"), [
            'Content-Type' => self::IMAGE_TYPES[$ext],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Security-Policy' => "default-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
