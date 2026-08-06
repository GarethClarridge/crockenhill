<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\InboundEmailStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailgunInboundEmailRequest;
use App\Jobs\ProcessInboundOosEmail;
use App\Models\InboundEmail;
use App\Services\Import\ImportIngressGate;
use Illuminate\Http\JsonResponse;

class MailgunInboundWebhookController extends Controller
{
    public function __construct(
        private readonly ImportIngressGate $ingress,
    ) {}

    /**
     * Handle an inbound email webhook from Mailgun.
     */
    public function __invoke(StoreMailgunInboundEmailRequest $request): JsonResponse
    {
        $inboundEmail = InboundEmail::query()->firstOrCreate(
            ['message_id' => (string) $request->messageId()],
            [
                'from' => (string) $request->input('from'),
                'subject' => (string) $request->input('subject'),
                'body_plain' => $request->bodyPlain(),
                'body_html' => $request->bodyHtml(),
                'received_at' => $request->receivedAt(),
                'status' => InboundEmailStatus::Pending->value,
                'processing_metadata' => $request->processingMetadata(),
            ],
        );

        if (! $inboundEmail->wasRecentlyCreated) {
            // Allow recovery: a redelivery of a previously-failed email should trigger
            // reprocessing rather than being silently swallowed as a duplicate.
            if ($inboundEmail->status !== InboundEmailStatus::Failed) {
                return response()->json([
                    'status' => 'duplicate',
                ]);
            }

            $inboundEmail->update(['status' => InboundEmailStatus::Pending]);
        }

        /**
         * §15.2 requires this route to stay lossless while a production import
         * window holds the ingress lock. The email is already staged durably
         * above, so the window only defers the processing it would trigger:
         * Mailgun still gets its 202 and never retries or drops the delivery,
         * and `import:ingress release` picks the row up afterwards.
         *
         * Refusing here instead would push the order of service onto Mailgun's
         * retry schedule, which is the one thing this route must not risk.
         */
        if ($this->ingress->isBlocked()) {
            return response()->json([
                'status' => 'deferred',
            ], 202);
        }

        ProcessInboundOosEmail::dispatch($inboundEmail);

        return response()->json([
            'status' => 'accepted',
        ], 202);
    }
}
