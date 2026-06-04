<?php

declare(strict_types=1);

namespace App\Actions\InboundEmail;

use App\Data\OosEmailParseResult;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosEmailParserService;
use Throwable;

class ApproveInboundEmailImport
{
    public function __construct(
        private readonly OosEmailParserService $parser,
        private readonly InboundEmailImportService $importService,
    ) {}

    /**
     * Approve an inbound email for direct import.
     *
     * Uses the stored parse result when available, falling back to a fresh parse.
     * Returns a ChurchService on success, or an error message string on failure.
     */
    public function execute(InboundEmail $inboundEmail, ?int $reviewedByUserId): ChurchService|string
    {
        try {
            $parseResult = $this->importService->storedParseResult($inboundEmail);

            if (! $parseResult instanceof OosEmailParseResult) {
                $parseResult = $this->parser->parse($inboundEmail);
                $this->importService->storeParseResult($inboundEmail, $parseResult);
            }

            if (! $this->canApprove($parseResult)) {
                return 'This email still needs manual editing before it can be approved.';
            }

            return $this->importService->import(
                $inboundEmail,
                $parseResult,
                reviewedByUserId: $reviewedByUserId,
                reviewMode: 'direct_approve',
            );
        } catch (Throwable $exception) {
            report($exception);

            return 'Unable to approve this inbound email right now.';
        }
    }

    private function canApprove(OosEmailParseResult $parseResult): bool
    {
        return is_string($parseResult->date)
            && $parseResult->service instanceof SermonService
            && $parseResult->items !== [];
    }
}
