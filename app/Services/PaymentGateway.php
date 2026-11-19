<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/** Creates and verifies online payments for the booking advance. */
class PaymentGateway
{
    public static function driver(): string
    {
        $driver = config('payments.driver');
        if ($driver === 'simulate' && app()->isProduction()) {
            throw new \RuntimeException('The simulated payment gateway cannot be used in production');
        }

        return $driver;
    }

    /**
     * Start a payment of $rupees for an appointment.
     *
     * @return array{driver: string, order_id: string, amount: int, currency: string, key_id: ?string, sim_signature?: string}
     */
    public static function createOrder(float $rupees, string $receipt): array
    {
        $paise = (int) round($rupees * 100);
        if (self::driver() === 'simulate') {
            $id = 'sim_'.bin2hex(random_bytes(8));

            return ['driver' => 'simulate', 'order_id' => $id, 'amount' => $paise, 'currency' => 'INR', 'key_id' => null, 'sim_signature' => self::simSignature($id)];
        }

        $cfg = config('payments.razorpay');
        if (! $cfg['key_id'] || ! $cfg['key_secret']) {
            throw new \RuntimeException('Razorpay is not configured');
        }
        $res = Http::timeout(10)->withBasicAuth($cfg['key_id'], $cfg['key_secret'])->post($cfg['api'].'/orders', ['amount' => $paise, 'currency' => 'INR', 'receipt' => $receipt]);
        if (! $res->successful() || ! $res->json('id')) {
            throw new \RuntimeException('Razorpay refused the order: '.mb_substr($res->body(), 0, 300));
        }

        return ['driver' => 'razorpay', 'order_id' => $res->json('id'), 'amount' => $paise, 'currency' => 'INR', 'key_id' => $cfg['key_id']];
    }

    /** The details needed to open the payment window again for an order created earlier. */
    public static function existingOrder(string $orderId, float $rupees): array
    {
        $paise = (int) round($rupees * 100);
        if (self::driver() === 'simulate') {
            return ['driver' => 'simulate', 'order_id' => $orderId, 'amount' => $paise, 'currency' => 'INR', 'key_id' => null, 'sim_signature' => self::simSignature($orderId)];
        }

        return ['driver' => 'razorpay', 'order_id' => $orderId, 'amount' => $paise, 'currency' => 'INR', 'key_id' => config('payments.razorpay.key_id')];
    }

    private static function simSignature(string $orderId): string
    {
        return hash_hmac('sha256', 'simulate|'.$orderId, (string) config('app.key'));
    }

    /** Was this payment really made for this order? */
    public static function verifyPayment(string $orderId, string $paymentId, string $signature): bool
    {
        if (self::driver() === 'simulate') {
            return hash_equals(self::simSignature($orderId), $signature);
        }
        $secret = (string) config('payments.razorpay.key_secret');

        return $secret !== '' && hash_equals(hash_hmac('sha256', $orderId.'|'.$paymentId, $secret), $signature);
    }

    /** Is this webhook call really from Razorpay? */
    public static function verifyWebhook(string $rawBody, string $signature): bool
    {
        $secret = (string) config('payments.razorpay.webhook_secret');

        return $secret !== '' && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }
}
