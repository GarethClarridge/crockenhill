<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sermon;
use App\Services\SermonStorageService;
use App\Support\Path;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class SermonThumbnailCandidateController extends Controller
{
    public function __construct(
        private readonly SermonStorageService $storageService,
    ) {}

    public function show(Sermon $sermon, string $candidateId, string $variant): BinaryFileResponse
    {
        if (! preg_match('/^candidate-\d+$/', $candidateId)) {
            abort(404);
        }

        $thumbnailPath = $this->storageService->getThumbnailCandidatePath($sermon, $candidateId, $variant);

        if (! is_string($thumbnailPath) || $thumbnailPath === '') {
            abort(404, 'Thumbnail candidate not found.');
        }

        if (Path::isUnsafe($thumbnailPath)) {
            abort(404, 'Invalid thumbnail file path.');
        }

        $disk = $this->storageService->resolveThumbnailDisk($thumbnailPath);

        if (! Storage::disk($disk)->exists($thumbnailPath)) {
            abort(404, 'Thumbnail file not found.');
        }

        $path = Storage::disk($disk)->path($thumbnailPath);
        $name = basename($thumbnailPath);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $contentType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return response()->file($path, [
            'Content-Type' => $contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name),
            'Cache-Control' => 'no-store',
        ]);
    }
}
