<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Services\SermonExposurePolicy;
use App\Services\SermonStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class SermonAssetController extends Controller
{
    public function __construct(
        private readonly SermonStorageService $storageService,
        private readonly SermonExposurePolicy $exposurePolicy,
    ) {}

    /**
     * Serve audio file for a sermon
     */
    public function serveAudio(Sermon $sermon): BinaryFileResponse|RedirectResponse
    {
        // Security check: Authorize access to Children's Talk assets
        if ($sermon->content_type === SermonContentType::ChildrensTalk && ! $this->exposurePolicy->canAccessChildrensCorner(Auth::user())) {
            return redirect()->guest(route('login'));
        }

        if (! $sermon->audio_file_path) {
            abort(404, 'Audio file not found.');
        }

        // Security check: Prevent path traversal
        if (str_contains($sermon->audio_file_path, '..')) {
            abort(404, 'Invalid audio file path.');
        }

        $storageService = $this->storageService;
        $fileInfo = $storageService->getSermonFileInfo($sermon);

        if (! Storage::disk($fileInfo['disk'])->exists($fileInfo['path'])) {
            abort(404, 'Audio file not found.');
        }

        if (! str_starts_with($sermon->audio_file_path, 'private/')) {
            return redirect()->to($storageService->getPublicUrl($sermon));
        }

        $path = Storage::disk($fileInfo['disk'])->path($fileInfo['path']);
        $name = basename($fileInfo['path']);

        return response()->file($path, [
            'Content-Type' => 'audio/mpeg',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Serve thumbnail image for a sermon
     */
    public function serveThumbnail(Sermon $sermon): BinaryFileResponse|RedirectResponse
    {
        // Security check: Authorize access to Children's Talk assets
        if ($sermon->content_type === SermonContentType::ChildrensTalk && ! $this->exposurePolicy->canAccessChildrensCorner(Auth::user())) {
            return redirect()->guest(route('login'));
        }

        if (! $sermon->thumbnail_file_path) {
            abort(404, 'Thumbnail not found.');
        }

        // Security check: Prevent path traversal
        if (str_contains($sermon->thumbnail_file_path, '..')) {
            abort(404, 'Invalid thumbnail file path.');
        }

        $disk = str_starts_with($sermon->thumbnail_file_path, 'private/')
            ? 'local'
            : config('thumbnail-generation.storage.disk', 'public');

        if (! Storage::disk($disk)->exists($sermon->thumbnail_file_path)) {
            abort(404, 'Thumbnail file not found.');
        }

        if (! str_starts_with($sermon->thumbnail_file_path, 'private/')) {
            return redirect()->to((string) $this->storageService->getThumbnailUrl($sermon));
        }

        return $this->serveStoredThumbnail($sermon->thumbnail_file_path);
    }

    /**
     * Serve the thumbnail variant intended for compact UI cards.
     */
    public function serveCardThumbnail(Sermon $sermon): BinaryFileResponse|RedirectResponse
    {
        // Security check: Authorize access to Children's Talk assets
        if ($sermon->content_type === SermonContentType::ChildrensTalk && ! $this->exposurePolicy->canAccessChildrensCorner(Auth::user())) {
            return redirect()->guest(route('login'));
        }

        $cardThumbnailPath = $sermon->card_thumbnail_file_path;

        if (! $cardThumbnailPath) {
            abort(404, 'Card thumbnail not found.');
        }

        // Security check: Prevent path traversal
        if (str_contains($cardThumbnailPath, '..')) {
            abort(404, 'Invalid thumbnail file path.');
        }

        $disk = str_starts_with($cardThumbnailPath, 'private/')
            ? 'local'
            : config('thumbnail-generation.storage.disk', 'public');

        if (! Storage::disk($disk)->exists($cardThumbnailPath)) {
            abort(404, 'Thumbnail file not found.');
        }

        $cardThumbnailUrl = $this->storageService->getCardThumbnailUrl($sermon);
        if ($cardThumbnailUrl !== null && ! str_starts_with($cardThumbnailPath, 'private/')) {
            return redirect()->to($cardThumbnailUrl);
        }

        return $this->serveStoredThumbnail($cardThumbnailPath);
    }

    private function serveStoredThumbnail(string $thumbnailPath): BinaryFileResponse
    {
        // Security check: Prevent path traversal
        if (str_contains($thumbnailPath, '..')) {
            abort(404, 'Invalid thumbnail file path.');
        }

        $disk = str_starts_with($thumbnailPath, 'private/')
            ? 'local'
            : config('thumbnail-generation.storage.disk', 'public');

        if (! Storage::disk($disk)->exists($thumbnailPath)) {
            abort(404, 'Thumbnail file not found.');
        }

        $path = Storage::disk($disk)->path($thumbnailPath);
        $name = basename($thumbnailPath);

        // Determine content type based on file extension
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        $lastModifiedTime = filemtime($path);
        $lastModified = $lastModifiedTime === false
            ? gmdate('D, d M Y H:i:s').' GMT'
            : gmdate('D, d M Y H:i:s', $lastModifiedTime).' GMT';

        return response()->file($path, [
            'Content-Type' => $contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name),
            'Cache-Control' => 'private, no-store',
            'ETag' => md5_file($path),
            'Last-Modified' => $lastModified,
        ]);
    }
}
