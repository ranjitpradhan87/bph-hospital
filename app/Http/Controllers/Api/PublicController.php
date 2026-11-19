<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PublicSite;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Unauthenticated endpoints for the public website. No patient data is ever returned here. */
class PublicController extends Controller
{
    public function settings(): JsonResponse
    {
        return response()->json(PublicSite::settings());
    }

    public function departments(): JsonResponse
    {
        return response()->json(PublicSite::departments());
    }

    public function department(string $slug): JsonResponse
    {
        return response()->json(PublicSite::department($slug) ?? abort(404, 'Department not found'));
    }

    public function specializations(): JsonResponse
    {
        return response()->json(PublicSite::specializations());
    }

    public function doctors(Request $request): JsonResponse
    {
        $request->validate([
            'search' => 'nullable|string|max:100', 'department' => 'nullable|string|max:120', 'specialization' => 'nullable|string|max:120',
            'available_on' => 'nullable|date_format:Y-m-d', 'featured' => 'nullable|in:0,1,true,false', 'page' => 'integer|min:1', 'page_size' => 'integer|min:1|max:50',
        ]);

        return response()->json(PublicSite::doctors($request->only(['search', 'department', 'specialization', 'available_on', 'page', 'page_size']) + ['featured' => $request->boolean('featured')]));
    }

    public function doctor(int $id): JsonResponse
    {
        return response()->json(PublicSite::doctor($id) ?? abort(404, 'Doctor not found'));
    }

    public function doctorSlots(Request $request, int $id): JsonResponse
    {
        $on = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];

        return response()->json(PublicSite::doctorSlots($id, $on) ?? abort(404, 'Doctor not found'));
    }

    public function contact(Request $request): JsonResponse
    {
        if ($request->filled('phone')) {
            $request->merge(['phone' => Phone::normalize((string) $request->input('phone'))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'], 'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'regex:/^[6-9]\d{9}$/'], 'subject' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:2000'], 'website' => ['nullable', 'string'],
        ], ['phone.regex' => 'Enter a valid 10-digit Indian mobile number']);
        PublicSite::submitContact($data);

        return response()->json(['status' => 'received'], 201);
    }
}
