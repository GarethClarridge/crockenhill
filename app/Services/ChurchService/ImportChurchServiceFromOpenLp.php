<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Data\OpenLpImportResult;
use App\Data\OpenLpParseResult;
use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
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
    ) {}

    public function import(UploadedFile $uploadedFile): OpenLpImportResult
    {
        $parsed = $this->parser->parse($uploadedFile);

        $existingService = ChurchService::query()
            ->where('date', $parsed->date)
            ->where('service', $parsed->service->value)
            ->first();

        if ($existingService instanceof ChurchService) {
            return $this->importIntoExistingService($uploadedFile, $parsed, $existingService);
        }

        return $this->importAsNewService($uploadedFile, $parsed);
    }

    /**
     * @throws ModelNotFoundException
     */
    private function importIntoExistingService(
        UploadedFile $uploadedFile,
        OpenLpParseResult $parsed,
        ChurchService $existingService,
    ): OpenLpImportResult {
        try {
            $existingMetadata = $existingService->import_metadata?->toArray() ?? [];

            // Update import provenance metadata and filename, but defer the source field
            // update until after the merge decision — if the merge is staged for review the
            // service items are still livestream-derived and source should reflect that.
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

        $linkResult = [
            'dry_run' => false,
            'processed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'cleared' => 0,
        ];

        if ($mergeResult->wasMerged) {
            // Only update source to openlp once items have actually been applied.
            $mergeResult->churchService->forceFill([
                'source' => ChurchServiceItemSource::OpenLp->value,
            ])->saveQuietly();

            $linkResult = $this->songLinker->linkForService($mergeResult->churchService);
        }

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
        ];

        try {
            $churchService = DB::transaction(function () use ($uploadedFile, $parsed, &$wasCreated, &$syncResult, &$linkResult): ChurchService {
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

                return $churchService;
            });
        } catch (UniqueConstraintViolationException) {
            $churchService = ChurchService::query()
                ->where('date', $parsed->date)
                ->where('service', $parsed->service->value)
                ->firstOrFail();
            $wasCreated = false;
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
}
