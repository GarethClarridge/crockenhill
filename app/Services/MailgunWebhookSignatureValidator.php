<?php

declare(strict_types=1);

namespace App\Services;

class MailgunWebhookSignatureValidator
{
    public function isValid(string $timestamp, string $token, string $signature): bool
    {
        $signingKey = (string) config('service-tracking.mailgun.signing_key', config('services.mailgun.signing_key', ''));

        if ($signingKey === '') {
            return false;
        }

        $timestampTolerance = (int) config('service-tracking.mailgun.timestamp_tolerance_seconds', 300);

        if (! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > $timestampTolerance) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $timestamp.$token, $signingKey);

        if (! hash_equals($expectedSignature, $signature)) {
            return false;
        }

        // Replay protection: Ensure the same token cannot be reused within the valid timeframe.
        // We use Cache::add which only returns true if the key does not already exist.
        $cacheKey = "mailgun_webhook_token:{$token}";

        return \Illuminate\Support\Facades\Cache::add(
            $cacheKey,
            true,
            $timestampTolerance * 2
        );
    }
}
