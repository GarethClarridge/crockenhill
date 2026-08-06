<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ImportIngressLock;
use App\Services\Import\ImportIngressGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * §15.2. Refuse submissions that would admit new import work while a production
 * import window holds the ingress lock.
 *
 * 503 with `Retry-After` is deliberate: it tells an API client and a human the
 * same true thing — the work was not accepted, the refusal is temporary, and
 * roughly when to come back. A 403 would read as "you may not do this", and a
 * 200 that silently dropped the submission would be the loss this guards
 * against.
 */
class RefuseBlockedImportIngress
{
    /** Conservative retry hint; the real window budget is §15.2's accepted cap. */
    private const int RetryAfterSeconds = 900;

    public function __construct(
        private readonly ImportIngressGate $gate,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $lock = $this->gate->active();

        if (! $lock instanceof ImportIngressLock) {
            return $next($request);
        }

        $message = 'New import and media-processing submissions are paused for a scheduled archive import. '
            .'Your submission was not accepted; please retry shortly.';

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'import_ingress_blocked',
                'message' => $message,
                'operation_id' => $lock->operation_id,
                'retry_after' => self::RetryAfterSeconds,
            ], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => (string) self::RetryAfterSeconds]);
        }

        return response($message, Response::HTTP_SERVICE_UNAVAILABLE, [
            'Retry-After' => (string) self::RetryAfterSeconds,
        ]);
    }
}
