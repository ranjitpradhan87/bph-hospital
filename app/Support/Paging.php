<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class Paging
{
    /** Standard list envelope used by every list endpoint: {items, total, page, page_size}. */
    public static function respond(Request $request, $query, callable $map): JsonResponse
    {
        $request->validate(['page' => 'integer|min:1', 'page_size' => 'integer|min:1|max:100']);
        $size = (int) $request->query('page_size', 20);
        $page = $query->paginate($size, ['*'], 'page', (int) $request->query('page', 1));

        return response()->json([
            'items' => collect($page->items())->map($map)->values(),
            'total' => $page->total(), 'page' => $page->currentPage(), 'page_size' => $size,
        ]);
    }
}
