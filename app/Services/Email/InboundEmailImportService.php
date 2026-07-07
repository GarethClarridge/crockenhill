<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Data\OosEmailParseResult;
use App\Enums\ChurchServiceItemSource;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\ChurchService\ChurchServiceCanonicalUpdateService;
use App\Services\ChurchService\ChurchServiceItemSyncService;
use App\Services\ChurchService\ChurchServiceSongLinker;
use App\Services\ChurchService\ChurchServiceStructureMergeService;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class InboundEmailImportService
{
    use SanitizesLogData;

    public function __construct(
        private readonly ChurchServiceCanonicalUpdateService $canonicalUpdateService,
        private readonly ChurchServiceItemSyncService $itemSyncService,
        private readonly ChurchServiceSongLinker $songLinker,
        private readonly ChurchServiceStructureMergeService $mergeService,
    ) {}

    public function storeParseResult(InboundEmail $inboundEmail, OosEmailParseResult $parseResult, bool $isReparse = false): void
    {
        $metadata = [
            'parsing' => array_replace_recursive($parseResult->importMetadata, [
                'resolved_date' => $parseResult->date,
                'resolved_service' => $parseResult->service?->value,
                'items' => $parseResult->items,
                'needs_review' => $parseResult->needsReview,
                'should_import' => $parseResult->shouldImport,
            ]),
        ];

        if ($isReparse) {
            $metadata['reparsed_at'] = now()->toIso8601String();
            $metadata['failure'] = null;
        }

        $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
            $inboundEmail->processing_metadata,
            $metadata,
        );
        $inboundEmail->save();
    }

    public function storedParseResult(InboundEmail $inboundEmail): ?OosEmailParseResult
    {
        $processingMetadata = is_array($inboundEmail->processing_metadata) ? $inboundEmail->processing_metadata : [];
        $storedParseData = Arr::get($processingMetadata, 'parsing');

        if (! is_array($storedParseData) || ! is_array($storedParseData['items'] ?? null)) {
            return null;
        }

        $resolvedDate = Arr::get($storedParseData, 'resolved_date');
        $resolvedService = Arr::get($storedParseData, 'resolved_service');
        $confidenceScore = Arr::get($storedParseData, 'confidence_score');

        return new OosEmailParseResult(
            date: is_string($resolvedDate) && $resolvedDate !== '' ? $resolvedDate : null,
            service: is_string($resolvedService) ? SermonService::tryFrom($resolvedService) : null,
            items: $this->storedItems($storedParseData['items']),
            confidenceScore: is_numeric($confidenceScore) ? round((float) $confidenceScore, 2) : 0.0,
            needsReview: (bool) Arr::get($storedParseData, 'needs_review', false),
            shouldImport: (bool) Arr::get($storedParseData, 'should_import', false),
            importMetadata: Arr::except($storedParseData, [
                'resolved_date',
                'resolved_service',
                'items',
                'needs_review',
                'should_import',
            ]),
        );
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{position:int,type:string,title:string,source_title:?string,openlp_search_title:?string,metadata:?array<string,mixed>}>
     */
    private function storedItems(array $items): array
    {
        $storedItems = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $position = $item['position'] ?? null;
            $type = $item['type'] ?? null;
            $title = $item['title'] ?? null;
            $sourceTitle = $item['source_title'] ?? null;
            $openLpSearchTitle = $item['openlp_search_title'] ?? null;
            $metadata = $item['metadata'] ?? null;

            if (! is_int($position) || ! is_string($type) || ! is_string($title)) {
                continue;
            }

            $storedItems[] = [
                'position' => $position,
                'type' => $type,
                'title' => $title,
                'source_title' => is_string($sourceTitle) ? $sourceTitle : null,
                'openlp_search_title' => is_string($openLpSearchTitle) ? $openLpSearchTitle : null,
                'metadata' => is_array($metadata) ? $metadata : null,
            ];
        }

        return $storedItems;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function import(
        InboundEmail $inboundEmail,
        OosEmailParseResult $parseResult,
        ?int $reviewedByUserId = null,
        string $reviewMode = 'direct_approve',
    ): ChurchService {
        if (! is_string($parseResult->date) || $parseResult->service === null) {
            throw new InvalidArgumentException('Inbound email parse result is missing a date or service.');
        }

        $existingService = ChurchService::query()
            ->where('date', $parseResult->date)
            ->where('service', $parseResult->service->value)
            ->first();

        $importMetadata = $parseResult->importMetadata;

        if ($reviewedByUserId !== null) {
            $importMetadata = array_replace_recursive($importMetadata, [
                'admin_review' => [
                    'approved_at' => now()->toIso8601String(),
                    'approved_by_user_id' => $reviewedByUserId,
                    'mode' => $reviewMode,
                ],
            ]);
        }

        if ($existingService instanceof ChurchService) {
            return $this->importIntoExistingService(
                $inboundEmail, $parseResult, $existingService, $importMetadata, $reviewedByUserId, $reviewMode,
            );
        }

        return $this->importAsNewService(
            $inboundEmail, $parseResult, $importMetadata, $reviewedByUserId, $reviewMode,
        );
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function importIntoExistingService(
        InboundEmail $inboundEmail,
        OosEmailParseResult $parseResult,
        ChurchService $existingService,
        array $importMetadata,
        ?int $reviewedByUserId,
        string $reviewMode,
    ): ChurchService {
        $existingMetadata = $existingService->import_metadata?->toArray() ?? [];

        // Update import provenance metadata, but defer the source field update until after
        // the merge decision — if the merge is staged for review the service items are still
        // livestream-derived and source should continue to reflect their actual provenance.
        $existingService->fill([
            'needs_review' => $reviewedByUserId === null ? $parseResult->needsReview : false,
            'import_metadata' => array_replace_recursive($existingMetadata, $importMetadata),
        ]);
        $existingService->save();

        $mergeResult = $this->mergeService->merge(
            $existingService,
            $parseResult->items,
            ChurchServiceItemSource::Email,
        );

        if ($mergeResult->wasMerged) {
            // Only update source to email once items have actually been applied.
            $mergeResult->churchService->forceFill([
                'source' => ChurchServiceItemSource::Email->value,
            ])->saveQuietly();

            $this->songLinker->linkForService($mergeResult->churchService);
        }

        $this->markEmailAsProcessed($inboundEmail, $mergeResult->churchService, $reviewedByUserId, $reviewMode);

        Log::warning('Church service imported from email (existing)', $this->sanitizeArrayForLog([
            'admin_id' => $reviewedByUserId,
            'church_service_id' => $existingService->id,
            'email_id' => $inboundEmail->id,
            'was_merged' => $mergeResult->wasMerged,
        ]));

        return $mergeResult->churchService;
    }

    /**
     * @param  array<string, mixed>  $importMetadata
     */
    private function importAsNewService(
        InboundEmail $inboundEmail,
        OosEmailParseResult $parseResult,
        array $importMetadata,
        ?int $reviewedByUserId,
        string $reviewMode,
    ): ChurchService {
        $beforeSnapshot = [];
        $syncResult = [];

        /** @var SermonService $service */
        $service = $parseResult->service;

        $churchService = DB::transaction(function () use ($parseResult, $service, $importMetadata, $reviewedByUserId, &$syncResult): ChurchService {
            $churchService = ChurchService::query()->firstOrNew([
                'date' => $parseResult->date,
                'service' => $service->value,
            ]);
            $existingMetadata = $churchService->import_metadata?->toArray() ?? [];

            $churchService->fill([
                'source' => ChurchServiceItemSource::Email->value,
                'needs_review' => $reviewedByUserId === null ? $parseResult->needsReview : false,
                'import_metadata' => array_replace_recursive($existingMetadata, $importMetadata),
            ]);
            $churchService->save();

            $syncResult = $this->itemSyncService->sync($churchService, $parseResult->items, ChurchServiceItemSource::Email);
            $this->songLinker->linkForService($churchService);

            /** @var ChurchService $freshChurchService */
            $freshChurchService = $churchService->fresh(['items']) ?? $churchService;

            return $freshChurchService;
        });

        $this->markEmailAsProcessed($inboundEmail, $churchService, $reviewedByUserId, $reviewMode);

        Log::warning('Church service imported from email (new)', $this->sanitizeArrayForLog([
            'admin_id' => $reviewedByUserId,
            'church_service_id' => $churchService->id,
            'email_id' => $inboundEmail->id,
        ]));

        return $this->canonicalUpdateService->finalize(
            $churchService,
            $beforeSnapshot,
            ChurchServiceItemSource::Email,
            $syncResult,
        );
    }

    private function markEmailAsProcessed(
        InboundEmail $inboundEmail,
        ChurchService $churchService,
        ?int $reviewedByUserId,
        string $reviewMode,
    ): void {
        $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
            $inboundEmail->processing_metadata,
            [
                'imported_church_service_id' => $churchService->id,
                'imported_at' => now()->toIso8601String(),
                'review' => $reviewedByUserId === null ? [] : [
                    'approved_at' => now()->toIso8601String(),
                    'approved_by_user_id' => $reviewedByUserId,
                    'mode' => $reviewMode,
                ],
            ],
        );
        $inboundEmail->status = InboundEmailStatus::Processed;
        $inboundEmail->save();
    }

    public function markAsProcessedFromManualReview(InboundEmail $inboundEmail, ChurchService $churchService, int $reviewedByUserId): void
    {
        $inboundEmail->processing_metadata = $this->mergeProcessingMetadata(
            $inboundEmail->processing_metadata,
            [
                'imported_church_service_id' => $churchService->id,
                'imported_at' => now()->toIso8601String(),
                'review' => [
                    'approved_at' => now()->toIso8601String(),
                    'approved_by_user_id' => $reviewedByUserId,
                    'mode' => 'manual_edit',
                ],
            ],
        );
        $inboundEmail->status = InboundEmailStatus::Processed;
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
