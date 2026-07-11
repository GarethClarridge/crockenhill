<?php

declare(strict_types=1);

namespace App\Actions\InboundEmail;

use App\Data\OosEmailImportResult;
use App\Data\OosEmailParseResult;
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
     * Approve an inbound email for direct import of all of its service plans.
     *
     * Uses the stored parse result when available, falling back to a fresh parse. Returns an
     * OosEmailImportResult (per-plan outcomes) on success, or an error message string.
     */
    public function execute(InboundEmail $inboundEmail, ?int $reviewedByUserId): OosEmailImportResult|string
    {
        try {
            $parseResult = $this->importService->storedParseResult($inboundEmail);

            if (! $parseResult instanceof OosEmailParseResult) {
                $parseResult = $this->parser->parse($inboundEmail);
                $this->importService->storeParseResult($inboundEmail, $parseResult);
            }

            // A parse stored before multi-service support has only a flattened item list, which
            // could import a morning+evening blob as a single service — require a re-parse first.
            if ($parseResult->isLegacyFlattened) {
                return 'This email was parsed before multi-service support was added. Re-parse it before approving.';
            }

            if ($parseResult->importablePlans() === []) {
                return 'This email still needs manual editing before it can be approved.';
            }

            $result = $this->importService->import(
                $inboundEmail,
                $parseResult,
                reviewedByUserId: $reviewedByUserId,
                reviewMode: 'direct_approve',
            );

            // A plan that failed to import (a DB or sync error) must not pass through the success
            // path — that would show the admin a "processed" toast while the email is still in the
            // inbox. Any confident orders remain imported; surface the failure so they retry.
            if ($result->hasFailures()) {
                return 'One or more orders failed to import. The email is still in the inbox — please try again.';
            }

            return $result;
        } catch (Throwable $exception) {
            report($exception);

            return 'Unable to approve this inbound email right now.';
        }
    }
}
