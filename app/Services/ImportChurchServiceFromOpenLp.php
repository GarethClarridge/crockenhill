<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\OpenLpImportResult;
use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ImportChurchServiceFromOpenLp
{
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

    private function importIntoExistingService(
        UploadedFile $uploadedFile,
        \App\Data\OpenLpParseResult $parsed,
        ChurchService $existingService,
    ): OpenLpImportResult {
        try {
            $existingMetadata = $existingService->import_metadata?->toArray() ?? [];

            $existingService->fill([
                'source' => ChurchServiceItemSource::OPENLP->value,
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
            ChurchServiceItemSource::OPENLP,
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
            $linkResult = $this->songLinker->linkForService($mergeResult->churchService);
        }

        return new OpenLpImportResult(
            churchService: $mergeResult->churchService,
            parseResult: $parsed,
            wasCreated: false,
            syncResult: $mergeResult->syncResult,
            linkResult: $linkResult,
        );
    }

    private function importAsNewService(
        UploadedFile $uploadedFile,
        \App\Data\OpenLpParseResult $parsed,
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
                    'source' => ChurchServiceItemSource::OPENLP->value,
                    'original_filename' => $uploadedFile->getClientOriginalName(),
                    'needs_review' => $parsed->needsReview,
                    'import_metadata' => array_replace_recursive($existingMetadata, $parsed->importMetadata),
                ]);
                $churchService->save();

                $syncResult = $this->itemSyncService->sync($churchService, $parsed->items, ChurchServiceItemSource::OPENLP);
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
            ChurchServiceItemSource::OPENLP,
            $syncResult,
        );

        return new OpenLpImportResult(
            churchService: $churchService,
            parseResult: $parsed,
            wasCreated: $wasCreated,
            syncResult: $syncResult,
            linkResult: $linkResult,
        );
    }
}
