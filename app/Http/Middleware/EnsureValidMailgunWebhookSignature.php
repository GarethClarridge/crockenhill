<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\MailgunWebhookSignatureValidator;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureValidMailgunWebhookSignature
{
    public function __construct(
        private readonly MailgunWebhookSignatureValidator $signatureValidator,
        private readonly CacheRepository $cache,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->input('timestamp');
        $token = $request->input('token');
        $signature = $request->input('signature');

        if (
            ! is_string($timestamp)
            || ! is_string($token)
            || ! is_string($signature)
            || ! $this->signatureValidator->isValid($timestamp, $token, $signature)
        ) {
            abort(403, 'Invalid Mailgun signature.');
        }

        $replayKey = 'mailgun_replay:'.$token;
        $tolerance = (int) config('service-tracking.mailgun.timestamp_tolerance_seconds', 300);

        if ($this->cache->has($replayKey)) {
            abort(403, 'Replayed Mailgun token.');
        }

        $this->cache->put($replayKey, true, $tolerance);

        return $next($request);
    }
}
