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
use Illuminate\Support\Facades\DB;

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
        [$inboundEmail, $duplicate, $deferred] = DB::transaction(function () use ($request): array {
            $lock = $this->ingress->activeForUpdate();
            $inboundEmail = InboundEmail::query()->firstOrCreate(
                ['message_id' => (string) $request->messageId()],
                [
                    'from' => (string) $request->input('from'),
                    'subject' => (string) $request->subject(),
                    'body_plain' => $request->bodyPlain(),
                    'body_html' => $request->bodyHtml(),
                    'received_at' => $request->receivedAt(),
                    'status' => InboundEmailStatus::Pending->value,
                    'processing_metadata' => $request->processingMetadata(),
                ],
            );
            $duplicate = ! $inboundEmail->wasRecentlyCreated;

            if ($duplicate && $inboundEmail->status === InboundEmailStatus::Failed) {
                $inboundEmail->update(['status' => InboundEmailStatus::Pending]);
                $duplicate = false;
            }

            if ($lock !== null) {
                $this->ingress->deferInboundEmail($lock, $inboundEmail);
            }

            return [$inboundEmail, $duplicate, $lock !== null];
        });

        if ($duplicate) {
            return response()->json(['status' => 'duplicate']);
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
        if ($deferred) {
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
