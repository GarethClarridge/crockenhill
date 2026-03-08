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

        return hash_equals($expectedSignature, $signature);
    }
}
