<?php

declare(strict_types=1);

namespace App\Actions\InboundEmail;

use App\Enums\InboundEmailStatus;
use App\Models\InboundEmail;
use App\Services\InboundEmailImportService;
use App\Services\OosEmailParserService;
use Throwable;

class ReparseInboundEmail
{
    public function __construct(
        private readonly OosEmailParserService $parser,
        private readonly InboundEmailImportService $importService,
    ) {}

    /**
     * Re-parse an inbound email without importing it.
     *
     * Updates stored parsing metadata, clears any prior failure record, and transitions
     * a FAILED email back to PENDING so it becomes reviewable again.
     * Returns null on success, or an error string on failure.
     */
    public function execute(InboundEmail $inboundEmail): ?string
    {
        try {
            $parseResult = $this->parser->parse($inboundEmail);
            $this->importService->storeParseResult($inboundEmail, $parseResult, isReparse: true);

            // Explicitly clear failure metadata so the review UI no longer shows the old
            // error message, regardless of how storeParseResult handles the failure key.
            $inboundEmail->refresh();
            $metadata = is_array($inboundEmail->processing_metadata) ? $inboundEmail->processing_metadata : [];
            if (array_key_exists('failure', $metadata)) {
                unset($metadata['failure']);
                $inboundEmail->processing_metadata = $metadata;
            }

            if ($inboundEmail->status === InboundEmailStatus::Failed) {
                $inboundEmail->status = InboundEmailStatus::Pending;
            }

            $inboundEmail->save();

            return null;
        } catch (Throwable $exception) {
            report($exception);

            return 'Unable to re-parse this inbound email right now.';
        }
    }
}
