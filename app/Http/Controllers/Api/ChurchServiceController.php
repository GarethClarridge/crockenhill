<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadChurchServiceRequest;
use App\Http\Resources\ChurchServiceResource;
use App\Models\ChurchService;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ChurchServiceController extends Controller
{
    public function __construct(
        private readonly ImportChurchServiceFromOpenLp $importChurchServiceFromOpenLp,
    ) {}

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(UploadChurchServiceRequest $request): JsonResponse
    {
        $this->abortIfDisabled();

        $uploadedFile = $request->file('file');
        if (! $uploadedFile instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => 'Please upload a valid OpenLP .osz file.',
            ]);
        }

        $result = $this->importChurchServiceFromOpenLp->import($uploadedFile);

        return (new ChurchServiceResource($result->churchService))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @throws NotFoundHttpException
     */
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
