<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\InboundEmailStatus;
use App\Models\InboundEmail;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosEmailParserService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInboundOosEmail implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private InboundEmail $inboundEmail,
    ) {}

    public function handle(
        OosEmailParserService $parser,
        InboundEmailImportService $importService,
    ): void {
        $inboundEmail = $this->inboundEmail->fresh();
        if (! $inboundEmail instanceof InboundEmail) {
            return;
        }

        $parseResult = $parser->parse($inboundEmail);
        $importService->storeParseResult($inboundEmail, $parseResult);

        $autoImportablePlans = array_filter(
            $parseResult->servicePlans,
            static fn ($plan): bool => $plan->shouldImport,
        );

        if ($autoImportablePlans === []) {
            $inboundEmail->refresh();
            $inboundEmail->status = InboundEmailStatus::Pending;
            $inboundEmail->save();

            return;
        }

        // Imports every confident plan and holds the rest. If any plan was held (or failed) the
        // email is not fully resolved, so it stays Pending in the inbox with its confident
        // orders already imported and per-plan state recorded.
        $result = $importService->import($inboundEmail, $parseResult);

        if (! $result->isFullyResolved()) {
            $inboundEmail->refresh();

            if ($inboundEmail->status !== InboundEmailStatus::Processed) {
                $inboundEmail->status = InboundEmailStatus::Pending;
                $inboundEmail->save();
            }
        }
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['inbound_email:'.$this->inboundEmail->getKey()];
    }

    public function failed(\Throwable $exception): void
    {
        $inboundEmail = $this->inboundEmail->fresh();

        if (! $inboundEmail instanceof InboundEmail) {
            return;
        }

        $inboundEmail->status = InboundEmailStatus::Failed;
        $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
            $inboundEmail->processing_metadata,
            [
                'failure' => [
                    'message' => $exception->getMessage(),
                    'exception_class' => $exception::class,
                    'attempt' => $this->attempts(),
                    'queue_name' => $this->job?->getQueue(),
                    'failed_at' => now()->toIso8601String(),
                ],
            ],
        );
        $inboundEmail->save();
    }

    /**
     * @param  array<string, mixed>|null  $existingMetadata
     * @param  array<string, mixed>  $newMetadata
     * @return array<string, mixed>
     */
    private function mergeProcessingMetadata(?array $existingMetadata, array $newMetadata): array
    {
        $existingMetadata = is_array($existingMetadata) ? $existingMetadata : [];

        return array_replace_recursive($existingMetadata, $newMetadata);
    }
}
