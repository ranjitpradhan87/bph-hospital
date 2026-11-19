<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

final class Audit
{
    /** Record a sensitive action. Never put personal or clinical content in $changes: field names only. */
    public static function record(string $action, string $entity, int|string|null $id = null, ?array $changes = null, ?int $userId = null): void
    {
        $request = request();
        AuditLog::create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'entity_type' => $entity,
            'entity_id' => $id === null ? null : (string) $id,
            'changes' => $changes,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }
}
