<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EnquiryController extends Controller
{
    public const STATUSES = ['new', 'in_progress', 'closed'];

    public static function out(ContactMessage $m): array
    {
        return [
            'id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'phone' => $m->phone, 'subject' => $m->subject,
            'message' => $m->message, 'status' => $m->status, 'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', Rule::in(self::STATUSES)], 'page' => 'integer|min:1', 'page_size' => 'integer|min:1|max:100']);
        $q = ContactMessage::query();
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $size = (int) $request->query('page_size', 20);
        $page = $q->orderByDesc('created_at')->orderByDesc('id')->paginate($size, ['*'], 'page', (int) $request->query('page', 1));

        return response()->json([
            'items' => $page->getCollection()->map(fn ($m) => self::out($m))->values(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'page_size' => $size,
        ]);
    }

    public function update(Request $request, ContactMessage $enquiry): JsonResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(self::STATUSES)]])['status'];
        if ($enquiry->status !== $status) {
            Audit::record('update_status', 'contact_message', $enquiry->id, ['before' => $enquiry->status, 'after' => $status]);
            $enquiry->update(['status' => $status]);
        }

        return response()->json(self::out($enquiry));
    }
}
