<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: `permission:users.manage` (all listed) or `permission:a|b` (any of them).
 * Every protected API route declares this, so access never depends on the frontend hiding a button.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $spec): Response
    {
        $user = $request->user();
        if (! $user || ! $user->is_active || $user->trashed()) {
            return response()->json(['detail' => 'Not authenticated'], 401);
        }

        $any = str_contains($spec, '|');
        $codes = explode($any ? '|' : ',', $spec);
        $held = $user->permissionCodes();
        $ok = $any ? array_intersect($codes, $held) !== [] : array_diff($codes, $held) === [];

        return $ok ? $next($request) : response()->json(['detail' => 'You do not have permission to perform this action'], 403);
    }
}
