<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\ChurchService\ChurchServiceCanonicalStateService;
use App\Services\ChurchService\ChurchServiceCanonicalUpdateService;
use App\Services\ChurchService\ChurchServiceItemSyncService;
use App\Services\ChurchService\ChurchServiceSongLinker;
use App\Services\ChurchService\SourceAdapters\ManualSourceAdapter;
use App\Services\Email\InboundEmailImportService;
use App\Traits\SanitizesLogData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SaveChurchServiceFromAdmin
{
    use SanitizesLogData;

    public function __construct(
        private readonly ChurchServiceCanonicalStateService $canonicalStateService,
        private readonly ChurchServiceCanonicalUpdateService $canonicalUpdateService,
        private readonly ChurchServiceItemSyncService $itemSyncService,
        private readonly ChurchServiceSongLinker $songLinker,
        private readonly InboundEmailImportService $inboundEmailImportService,
        private readonly IngestChurchServiceSourceRevision $ingestSourceRevision,
        private readonly ManualSourceAdapter $manualSourceAdapter,
    ) {}

    /**
     * Persist a church service from the admin manual-edit form.
     *
     * Saves the service and its items inside a transaction, links songs, finalizes
     * canonical state, and marks the originating inbound email as processed when present.
     *
     * @param  array{date:string,service:string}  $validated
     * @param  array<int, array{position:int,type:string,title:string,source_title:string,openlp_search_title:null,song_id:int|null,metadata:array<string,mixed>|null}>  $syncPayload  `openlp_search_title` is always null for manual-source items (no OpenLP title to carry over)
     *
     * @throws RuntimeException
     */
    public function execute(
        array $validated,
        array $syncPayload,
        ?ChurchService $churchService,
        int $userId,
        ?int $inboundEmailId = null,
        ?string $planKey = null,
        ?int $expectedCanonicalRevision = null,
    ): ChurchService {
        $inboundEmail = $inboundEmailId !== null
            ? InboundEmail::query()->find($inboundEmailId)
            : null;

        /**
         * Always manual, including when an inbound email is being finished off.
         * The email is where the plan came from, but this write is a person stating
         * the whole list from the screen — which is what lets the save delete and
         * reorder rows other sources authored, and what stops the next re-parse of
         * the same email undoing the review. The email link itself is preserved by
         * markAsProcessedFromManualReview() below.
         */
        $incomingSource = ChurchServiceItemSource::Manual;

        $churchService = DB::transaction(function () use (
            $validated,
            $syncPayload,
            $churchService,
            $userId,
            $incomingSource,
            $expectedCanonicalRevision,
        ): ChurchService {
            $model = $churchService instanceof ChurchService
                ? ChurchService::query()
                    ->whereKey($churchService->getKey())
                    ->lockForUpdate()
                    ->firstOrFail()
                : new ChurchService;
            $loadedRevision = $expectedCanonicalRevision
                ?? ($churchService instanceof ChurchService ? $churchService->canonical_revision : 0);

            if ($model->exists && $model->canonical_revision !== $loadedRevision) {
                throw new RuntimeException('This service changed since you opened it. Reload the page and review the latest version before saving.');
            }

            $beforeSnapshot = $this->canonicalStateService->snapshot($model);
            $existingMetadata = $model->import_metadata?->toArray() ?? [];

            $model->fill([
                'date' => $validated['date'],
                'service' => $validated['service'],
                'source' => $incomingSource->value,
                'needs_review' => false,
                'import_metadata' => array_replace_recursive($existingMetadata, [
                    'manual_edit' => [
                        'saved_at' => now()->toIso8601String(),
                        'saved_by_user_id' => $userId,
                        'item_count' => count($syncPayload),
                    ],
                ]),
            ]);
            $model->save();

            try {
                $syncResult = $this->itemSyncService->sync($model, $syncPayload, $incomingSource);
            } catch (UniqueConstraintViolationException $exception) {
                if (str_contains($exception->getMessage(), 'church_service_items_active_position_unique')) {
                    throw new RuntimeException('Service item ordering conflict: the service could not be saved because two items share the same position. Please reload and try again.');
                }

                throw $exception;
            }

            $this->songLinker->linkForService($model);
            $this->ingestSourceRevision->execute(
                $model,
                $this->manualSourceAdapter->adapt(
                    array_values($syncPayload),
                    $userId,
                    [
                        'summary' => $model->summary,
                        'notices' => $model->notices,
                        'chapter_markers' => $model->chapter_markers,
                    ],
                ),
            );

            $model = $this->canonicalUpdateService->finalize(
                $model,
                $beforeSnapshot,
                $incomingSource,
                $syncResult,
            );
            $model->forceFill([
                'reviewed_canonical_revision' => $model->canonical_revision,
                'source_summary' => 'manual',
                'source' => ChurchServiceItemSource::Manual->value,
            ])->saveQuietly();

            return $model->fresh(['items']) ?? $model;
        });

        Log::warning('Church service saved by admin', $this->sanitizeArrayForLog([
            'admin_id' => $userId,
            'church_service_id' => $churchService->id,
            'date' => $churchService->date->toDateString(),
            'service' => $churchService->service->value,
            'item_count' => count($syncPayload),
        ]));

        if ($inboundEmail instanceof InboundEmail) {
            $this->inboundEmailImportService->markAsProcessedFromManualReview($inboundEmail, $churchService, $userId, $planKey);
        }

        return $churchService;
    }
}
