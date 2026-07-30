<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\OpenLpImportResult;
use App\Data\OpenLpParseResult;
use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Services\ChurchService\SourceAdapters\OpenLpSourceAdapter;
use App\Services\Song\OpenLpServiceParser;
use App\Traits\SanitizesLogData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportChurchServiceFromOpenLp
{
    use SanitizesLogData;

    public function __construct(
        private readonly OpenLpServiceParser $parser,
        private readonly ChurchServiceCanonicalUpdateService $canonicalUpdateService,
        private readonly ChurchServiceItemSyncService $itemSyncService,
        private readonly ChurchServiceSongLinker $songLinker,
        private readonly ChurchServiceStructureMergeService $mergeService,
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
        private readonly OpenLpSourceAdapter $sourceAdapter,
    ) {}

    /**
     * @throws ModelNotFoundException
     */
    public function import(UploadedFile $uploadedFile, ?string $batchHash = null): OpenLpImportResult
    {
        $parsed = $this->parser->parse($uploadedFile);

        $existingService = ChurchService::query()
            ->where('date', $parsed->date)
            ->where('service', $parsed->service->value)
            ->first();

        if ($existingService instanceof ChurchService) {
            return $this->importIntoExistingService($uploadedFile, $parsed, $existingService, $batchHash);
        }

        return $this->importAsNewService($uploadedFile, $parsed, $batchHash);
    }

    /**
     * @throws ModelNotFoundException
     */
    private function importIntoExistingService(
        UploadedFile $uploadedFile,
        OpenLpParseResult $parsed,
        ChurchService $existingService,
        ?string $batchHash,
    ): OpenLpImportResult {
        $linkResult = [
            'dry_run' => false,
            'processed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'cleared' => 0,
            'match_types' => [],
        ];

        $mergeResult = DB::transaction(function () use ($uploadedFile, $parsed, $existingService, $batchHash, &$linkResult) {
            try {
                $existingMetadata = $existingService->import_metadata?->toArray() ?? [];
                $existingService->fill([
                    'original_filename' => $uploadedFile->getClientOriginalName(),
                    'import_metadata' => array_replace_recursive($existingMetadata, $parsed->importMetadata),
                ]);
                $existingService->save();
            } catch (UniqueConstraintViolationException) {
                $existingService = ChurchService::query()
                    ->where('date', $parsed->date)
                    ->where('service', $parsed->service->value)
                    ->firstOrFail();
            }

            $mergeResult = $this->mergeService->merge(
                $existingService,
                $parsed->items,
                ChurchServiceItemSource::OpenLp,
            );

            if ($mergeResult->wasMerged) {
                $linkResult = $this->songLinker->linkForService($mergeResult->churchService);
            }

            $this->ingestSourceRevision->execute(
                $mergeResult->churchService,
                $this->sourceAdapter->adapt(
                    $uploadedFile,
                    $parsed,
                    $mergeResult->wasMerged ? $this->sourceItems($mergeResult->churchService) : null,
                    $batchHash,
                ),
            );

            return $mergeResult;
        });

        Log::warning('Church service imported from OpenLP (existing)', $this->sanitizeArrayForLog([
            'admin_id' => auth()->id(),
            'church_service_id' => $existingService->id,
            'filename' => $uploadedFile->getClientOriginalName(),
            'was_merged' => $mergeResult->wasMerged,
        ]));

        return new OpenLpImportResult(
            churchService: $mergeResult->churchService,
            parseResult: $parsed,
            wasCreated: false,
            syncResult: $mergeResult->syncResult,
            linkResult: $linkResult,
        );
    }

    /**
     * @throws ModelNotFoundException
     */
    private function importAsNewService(
        UploadedFile $uploadedFile,
        OpenLpParseResult $parsed,
        ?string $batchHash,
    ): OpenLpImportResult {
        $beforeSnapshot = [];
        $wasCreated = false;
        $syncResult = [];
        $linkResult = [
            'dry_run' => false,
            'processed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'cleared' => 0,
            'match_types' => [],
        ];

        try {
            $churchService = DB::transaction(function () use ($uploadedFile, $parsed, $batchHash, &$wasCreated, &$syncResult, &$linkResult): ChurchService {
                $churchService = ChurchService::query()->firstOrNew([
                    'date' => $parsed->date,
                    'service' => $parsed->service->value,
                ]);

                $wasCreated = ! $churchService->exists;
                $existingMetadata = $churchService->import_metadata?->toArray() ?? [];

                $churchService->fill([
                    'source' => ChurchServiceItemSource::OpenLp->value,
                    'original_filename' => $uploadedFile->getClientOriginalName(),
                    'needs_review' => $parsed->needsReview,
                    'import_metadata' => array_replace_recursive($existingMetadata, $parsed->importMetadata),
                ]);
                $churchService->save();

                $syncResult = $this->itemSyncService->sync($churchService, $parsed->items, ChurchServiceItemSource::OpenLp);
                $linkResult = $this->songLinker->linkForService($churchService);
                $this->ingestSourceRevision->execute(
                    $churchService,
                    $this->sourceAdapter->adapt($uploadedFile, $parsed, $this->sourceItems($churchService), $batchHash),
                );

                return $churchService;
            });
        } catch (UniqueConstraintViolationException) {
            $churchService = ChurchService::query()
                ->where('date', $parsed->date)
                ->where('service', $parsed->service->value)
                ->firstOrFail();

            return $this->importIntoExistingService($uploadedFile, $parsed, $churchService, $batchHash);
        }

        $churchService = $this->canonicalUpdateService->finalize(
            $churchService,
            $beforeSnapshot,
            ChurchServiceItemSource::OpenLp,
            $syncResult,
        );

        Log::warning('Church service imported from OpenLP (new)', $this->sanitizeArrayForLog([
            'admin_id' => auth()->id(),
            'church_service_id' => $churchService->id,
            'filename' => $uploadedFile->getClientOriginalName(),
        ]));

        return new OpenLpImportResult(
            churchService: $churchService,
            parseResult: $parsed,
            wasCreated: $wasCreated,
            syncResult: $syncResult,
            linkResult: $linkResult,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sourceItems(ChurchService $churchService): array
    {
        return array_values($churchService->items()
            ->orderBy('position')
            ->get()
            ->map(fn ($item): array => [
                'position' => $item->position,
                'type' => $item->type,
                'section_type' => $item->section_type?->value,
                'title' => $item->title,
                'source_title' => $item->source_title,
                'openlp_search_title' => $item->openlp_search_title,
                'song_id' => $item->song_id,
                'metadata' => $item->metadata,
            ])
            ->values()
            ->all());
    }
}
