<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\InboundEmailStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMailgunInboundEmailRequest;
use App\Jobs\ProcessInboundOosEmail;
use App\Models\InboundEmail;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MailgunInboundWebhookController extends Controller
{
    /**
     * Handle an inbound email webhook from Mailgun.
     *
     * @throws NotFoundHttpException If the service is disabled
     */
    public function __invoke(StoreMailgunInboundEmailRequest $request): JsonResponse
    {
        $this->abortIfDisabled();

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

        ProcessInboundOosEmail::dispatch($inboundEmail);

        return response()->json([
            'status' => 'accepted',
        ], 202);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
