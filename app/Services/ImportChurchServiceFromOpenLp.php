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
        private readonly ChurchServiceCanonicalStateService $canonicalStateService,
        private readonly ChurchServiceCanonicalUpdateService $canonicalUpdateService,
        private readonly ChurchServiceItemSyncService $itemSyncService,
        private readonly ChurchServiceSongLinker $songLinker,
    ) {}

    public function import(UploadedFile $uploadedFile): OpenLpImportResult
    {
        $parsed = $this->parser->parse($uploadedFile);

        $existingService = ChurchService::query()
            ->where('date', $parsed->date)
            ->where('service', $parsed->service->value)
            ->first();

        $beforeSnapshot = $this->canonicalStateService->snapshot($existingService);
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
