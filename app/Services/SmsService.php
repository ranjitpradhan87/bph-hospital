<?php

namespace App\Services;

use App\Support\SettingsRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Sends text messages and keeps a record of each one. A failed text never fails the action that caused it. */
class SmsService
{
    /**
     * @param  array<string, string>  $vars  values for the template's placeholders
     */
    public static function send(string $mobile, string $template, array $vars, ?int $userId = null, ?string $relatedType = null, ?int $relatedId = null): void
    {
        if (! SettingsRegistry::value('notifications.sms_enabled', true)) {
            return;
        }
        $cfg = config("sms.templates.$template") ?? throw new \InvalidArgumentException("Unknown SMS template $template");
        $body = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $vars[$m[1]] ?? '', $cfg['body']);
        $driver = config('sms.driver', 'log');
        $row = ['user_id' => $userId, 'mobile' => $mobile, 'template' => $template, 'body' => $body, 'driver' => $driver, 'related_type' => $relatedType, 'related_id' => $relatedId,
            'created_at' => now(), 'updated_at' => now()];

        try {
            if ($driver === 'msg91') {
                $ref = self::viaMsg91($mobile, $cfg, $vars);
                $id = DB::table('sms_messages')->insertGetId($row + ['status' => 'sent', 'provider_ref' => $ref]);
            } else {
                $id = DB::table('sms_messages')->insertGetId($row + ['status' => 'logged']);
            }
        } catch (Throwable $e) {
            report($e);
            DB::table('sms_messages')->insert($row + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    /** @return string|null the provider's request id */
    private static function viaMsg91(string $mobile, array $cfg, array $vars): ?string
    {
        $key = config('sms.msg91.auth_key');
        $tpl = $cfg['msg91_template_id'] ?? null;
        if (! $key || ! $tpl) {
            throw new \RuntimeException('MSG91 is not configured (auth key or template id missing)');
        }
        $recipient = ['mobiles' => '91'.$mobile];
        foreach ($cfg['vars'] as $i => $name) {
            $recipient['var'.($i + 1)] = (string) ($vars[$name] ?? '');
        }
        $res = Http::timeout(8)->withHeaders(['authkey' => $key, 'accept' => 'application/json'])
            ->post(config('sms.msg91.endpoint'), ['template_id' => $tpl, 'short_url' => '0', 'recipients' => [$recipient]]);
        if (! $res->successful() || ($res->json('type') ?? 'success') === 'error') {
            throw new \RuntimeException('MSG91 rejected the message: '.mb_substr($res->body(), 0, 300));
        }

        return $res->json('message');
    }
}
