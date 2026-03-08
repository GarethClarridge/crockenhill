<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ChurchServiceItemSource;
use App\Enums\InboundEmailStatus;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\ChurchServiceItemSyncService;
use App\Services\ChurchServiceSongLinker;
use App\Services\OosEmailParserService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessInboundOosEmail implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private InboundEmail $inboundEmail,
    ) {}

    public function handle(
        OosEmailParserService $parser,
        ChurchServiceItemSyncService $itemSyncService,
        ChurchServiceSongLinker $songLinker,
    ): void {
        $inboundEmail = $this->inboundEmail->fresh();
        if (! $inboundEmail instanceof InboundEmail) {
            return;
        }

        $parseResult = $parser->parse($inboundEmail);

        $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
            $inboundEmail->processing_metadata,
            ['parsing' => $parseResult->importMetadata],
        );

        if (! $parseResult->shouldImport) {
            $inboundEmail->status = InboundEmailStatus::PENDING;
            $inboundEmail->save();

            return;
        }

        DB::transaction(function () use (
            $inboundEmail,
            $parseResult,
            $itemSyncService,
            $songLinker,
        ): void {
            $churchService = ChurchService::query()->firstOrNew([
                'date' => $parseResult->date,
                'service' => $parseResult->service?->value,
            ]);

            $churchService->fill([
                'source' => ChurchServiceItemSource::EMAIL->value,
                'needs_review' => $parseResult->needsReview,
                'import_metadata' => $parseResult->importMetadata,
            ]);
            $churchService->save();

            $itemSyncService->sync($churchService, $parseResult->items, ChurchServiceItemSource::EMAIL);
            $songLinker->linkForService($churchService);
            $churchService->touchForSectionReconciliation();

            $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
                $inboundEmail->processing_metadata,
                [
                    'imported_church_service_id' => $churchService->id,
                    'imported_at' => now()->toIso8601String(),
                ],
            );
            $inboundEmail->status = InboundEmailStatus::PROCESSED;
            $inboundEmail->save();
        });
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

        $inboundEmail->status = InboundEmailStatus::FAILED;
        $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
            $inboundEmail->processing_metadata,
            [
                'failure' => [
                    'message' => $exception->getMessage(),
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
