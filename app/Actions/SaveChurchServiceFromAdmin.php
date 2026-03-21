<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ChurchServiceItemSource;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\ChurchServiceCanonicalStateService;
use App\Services\ChurchServiceCanonicalUpdateService;
use App\Services\ChurchServiceItemSyncService;
use App\Services\ChurchServiceSongLinker;
use App\Services\InboundEmailImportService;
use Illuminate\Support\Facades\DB;

class SaveChurchServiceFromAdmin
{
    public function __construct(
        private readonly ChurchServiceCanonicalStateService $canonicalStateService,
        private readonly ChurchServiceCanonicalUpdateService $canonicalUpdateService,
        private readonly ChurchServiceItemSyncService $itemSyncService,
        private readonly ChurchServiceSongLinker $songLinker,
        private readonly InboundEmailImportService $inboundEmailImportService,
    ) {}

    /**
     * Persist a church service from the admin manual-edit form.
     *
     * Saves the service and its items inside a transaction, links songs, finalizes
     * canonical state, and marks the originating inbound email as processed when present.
     *
     * @param  array{date:string,service:string}  $validated
     * @param  array<int, array{position:int,type:string,title:string,source_title:string,openlp_search_title:null,song_id:int|null,metadata:array<string,mixed>}>  $syncPayload  `openlp_search_title` is always null for manual-source items (no OpenLP title to carry over)
     */
    public function execute(
        array $validated,
        array $syncPayload,
        ?ChurchService $churchService,
        int $userId,
        ?int $inboundEmailId = null,
    ): ChurchService {
        $beforeSnapshot = $this->canonicalStateService->snapshot($churchService);

        /**
         * @var array{0: ChurchService, 1: array{conflicts: array<int, array<string, mixed>>}} $transactionResult
         */
        $transactionResult = DB::transaction(function () use ($validated, $syncPayload, $churchService, $userId): array {
            $model = $churchService ?? new ChurchService;
            $existingMetadata = $model->importMetadataData()->toArray();

            $model->fill([
                'date' => $validated['date'],
                'service' => $validated['service'],
                'source' => ChurchServiceItemSource::MANUAL->value,
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

            $syncResult = $this->itemSyncService->sync($model, $syncPayload, ChurchServiceItemSource::MANUAL);
            $this->songLinker->linkForService($model);

            return [$model->fresh(['items']) ?? $model, $syncResult];
        });

        [$churchService, $syncResult] = $transactionResult;

        $churchService = $this->canonicalUpdateService->finalize(
            $churchService,
            $beforeSnapshot,
            ChurchServiceItemSource::MANUAL,
            $syncResult,
        );

        if ($inboundEmailId !== null) {
            $inboundEmail = InboundEmail::query()->find($inboundEmailId);

            if ($inboundEmail instanceof InboundEmail) {
                $this->inboundEmailImportService->markAsProcessedFromManualReview($inboundEmail, $churchService, $userId);
            }
        }

        return $churchService;
    }
}
