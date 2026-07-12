<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NextChurchServiceRequest;
use App\Http\Requests\UploadChurchServiceRequest;
use App\Http\Resources\ChurchServiceResource;
use App\Models\ChurchService;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use Illuminate\Database\Eloquent\Builder;
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
     * @throws NotFoundHttpException
     * @throws ValidationException
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
     * Return the next upcoming service (today or later) with its canonical
     * item list, for the church PC's OpenLP assembly script.
     *
     * @throws NotFoundHttpException
     */
    public function next(NextChurchServiceRequest $request): ChurchServiceResource
    {
        $this->abortIfDisabled();

        $service = $request->validated()['service'] ?? null;

        $churchService = ChurchService::query()
            ->whereDate('date', '>=', now()->toDateString())
            ->when($service !== null, fn (Builder $query) => $query->where('service', $service))
            ->orderBy('date')
            ->orderByRaw("FIELD(service, 'morning', 'evening', 'other')")
            ->with(['items' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->firstOrFail();

        return new ChurchServiceResource($churchService);
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
