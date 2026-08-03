<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\OpenLpImportResult;
use App\Data\OpenLpParseResult;
use App\Data\StructureMergeResult;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Services\ChurchService\SourceAdapters\OpenLpSourceAdapter;
use App\Services\Song\OpenLpServiceParser;
use App\Traits\SanitizesLogData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ImportChurchServiceFromOpenLp
{
    use SanitizesLogData;

    public function __construct(
        private readonly OpenLpServiceParser $parser,
        private readonly ChurchServiceSongLinker $songLinker,
        private readonly ChurchServiceStructureMergeService $mergeService,
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
        private readonly OpenLpSourceAdapter $sourceAdapter,
        private readonly ChurchServiceCompatibilityMergeDecision $compatibilityMerge,
        private readonly ChurchServiceIdentityResolver $identityResolver,
    ) {}

    /**
     * @throws ModelNotFoundException
     */
    public function import(
        UploadedFile $uploadedFile,
        ?string $batchHash = null,
        ?string $resolvedDate = null,
        ?SermonService $resolvedService = null,
    ): OpenLpImportResult {
        $parsed = $this->parser->parse($uploadedFile);
        $parsed = $this->withManifestIdentity($parsed, $resolvedDate, $resolvedService);

        $existingService = $this->identityResolver->resolve($parsed->date, $parsed->service);

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

        $mergeResult = DB::transaction(function () use ($uploadedFile, $parsed, $existingService, $batchHash, &$linkResult): StructureMergeResult {
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

            $hadCanonicalItems = $existingService->items()->exists();
            $usesCompatibilityMerge = $this->compatibilityMerge->usesCompatibilityMerge($existingService);
            $ingestion = $this->ingestSourceRevision->execute(
                $existingService,
                $this->sourceAdapter->adapt(
                    $uploadedFile,
                    $parsed,
                    $batchHash,
                ),
                project: ! $usesCompatibilityMerge,
            );

            if (! $usesCompatibilityMerge) {
                $churchService = $existingService->fresh(['items']) ?? $existingService;
                $wasStaged = $churchService->mergeProposals()
                    ->where('trigger_source_record_id', $ingestion->sourceRecord->id)
                    ->where('status', ChurchServiceProposalStatus::Pending)
                    ->exists();
                $linkResult = $this->songLinker->linkForService($churchService);

                return new StructureMergeResult(
                    churchService: $churchService,
                    incomingSource: ChurchServiceItemSource::OpenLp,
                    wasMerged: ! $wasStaged,
                    wasStaged: $wasStaged,
                );
            }

            $mergeResult = $this->mergeService->merge(
                $existingService,
                $parsed->items,
                ChurchServiceItemSource::OpenLp,
            );

            if ($mergeResult->wasMerged && ! $hadCanonicalItems) {
                $mergeResult->churchService->forceFill([
                    'source' => ChurchServiceItemSource::OpenLp->value,
                ])->saveQuietly();
            }

            $linkResult = $this->songLinker->linkForService($mergeResult->churchService);

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

    private function withManifestIdentity(
        OpenLpParseResult $parsed,
        ?string $resolvedDate,
        ?SermonService $resolvedService,
    ): OpenLpParseResult {
        if ($resolvedDate === null && ! $resolvedService instanceof SermonService) {
            return $parsed;
        }

        if ($resolvedDate === null || ! $resolvedService instanceof SermonService) {
            throw new InvalidArgumentException('OpenLP manifest identity must specify both date and service.');
        }

        return new OpenLpParseResult(
            date: $resolvedDate,
            service: $resolvedService,
            items: $parsed->items,
            needsReview: $parsed->needsReview,
            importMetadata: $parsed->importMetadata,
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
        $wasCreated = false;
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
            $churchService = DB::transaction(function () use ($uploadedFile, $parsed, $batchHash, &$wasCreated, &$linkResult): ChurchService {
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

                $this->ingestSourceRevision->execute(
                    $churchService,
                    $this->sourceAdapter->adapt($uploadedFile, $parsed, $batchHash),
                );
                $linkResult = $this->songLinker->linkForService($churchService);

                return $churchService->fresh(['items']) ?? $churchService;
            });
        } catch (UniqueConstraintViolationException) {
            $churchService = ChurchService::query()
                ->where('date', $parsed->date)
                ->where('service', $parsed->service->value)
                ->firstOrFail();

            return $this->importIntoExistingService($uploadedFile, $parsed, $churchService, $batchHash);
        }

        Log::warning('Church service imported from OpenLP (new)', $this->sanitizeArrayForLog([
            'admin_id' => auth()->id(),
            'church_service_id' => $churchService->id,
            'filename' => $uploadedFile->getClientOriginalName(),
        ]));

        return new OpenLpImportResult(
            churchService: $churchService,
            parseResult: $parsed,
            wasCreated: $wasCreated,
            syncResult: [],
            linkResult: $linkResult,
        );
    }
}
