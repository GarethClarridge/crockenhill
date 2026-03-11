<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadChurchServiceRequest;
use App\Http\Resources\ChurchServiceResource;
use App\Models\ChurchService;
use App\Services\ChurchServiceCanonicalStateService;
use App\Services\ChurchServiceCanonicalUpdateService;
use App\Services\ChurchServiceItemSyncService;
use App\Services\ChurchServiceSongLinker;
use App\Services\OpenLpServiceParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChurchServiceController extends Controller
{
    public function __construct(
        private readonly OpenLpServiceParser $parser,
        private readonly ChurchServiceItemSyncService $itemSyncService,
        private readonly ChurchServiceSongLinker $songLinker,
        private readonly ChurchServiceCanonicalStateService $canonicalStateService,
        private readonly ChurchServiceCanonicalUpdateService $canonicalUpdateService,
    ) {}

    public function store(UploadChurchServiceRequest $request): JsonResponse
    {
        $this->abortIfDisabled();

        $uploadedFile = $request->file('file');
        if (! $uploadedFile instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => 'Please upload a valid OpenLP .osz file.',
            ]);
        }

        $parsed = $this->parser->parse($uploadedFile);

        $churchService = ChurchService::query()->firstOrNew([
            'date' => $parsed->date,
            'service' => $parsed->service->value,
        ]);
        $beforeSnapshot = $this->canonicalStateService->snapshot($churchService->exists ? $churchService : null);
        $syncResult = [];

        DB::transaction(function () use ($churchService, $uploadedFile, $parsed, &$syncResult): void {
            $existingMetadata = is_array($churchService->import_metadata) ? $churchService->import_metadata : [];

            $churchService->fill([
                'source' => 'openlp',
                'original_filename' => $uploadedFile->getClientOriginalName(),
                'needs_review' => $parsed->needsReview,
                'import_metadata' => array_replace_recursive($existingMetadata, $parsed->importMetadata),
            ]);
            $churchService->save();

            $syncResult = $this->itemSyncService->sync($churchService, $parsed->items);
            $this->songLinker->linkForService($churchService);
        });

        $churchService = $this->canonicalUpdateService->finalize(
            $churchService,
            $beforeSnapshot,
            'openlp',
            $syncResult,
        );

        $churchService->refresh()->load([
            'items' => fn ($query) => $query->orderBy('position')->orderBy('id'),
        ]);

        return (new ChurchServiceResource($churchService))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ChurchService $churchService): ChurchServiceResource
    {
        $this->abortIfDisabled();

        $churchService->load([
            'items' => fn ($query) => $query->orderBy('position')->orderBy('id'),
        ]);

        return new ChurchServiceResource($churchService);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
