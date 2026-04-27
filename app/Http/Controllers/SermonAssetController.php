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
        if (! $sermon->audio_file_path) {
            abort(404, 'Audio file not found.');
        }

        $authorizationResponse = $this->authorizeAssetAccess($sermon, $sermon->audio_file_path);
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        $this->abortOnUnsafePath($sermon->audio_file_path, 'audio');

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

    public function serveVideo(Sermon $sermon): BinaryFileResponse|RedirectResponse
    {
        if (! $sermon->video_file_path) {
            abort(404, 'Video file not found.');
        }

        $authorizationResponse = $this->authorizeAssetAccess($sermon, $sermon->video_file_path);
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        $this->abortOnUnsafePath($sermon->video_file_path, 'video');

        $disk = str_starts_with($sermon->video_file_path, 'private/')
            ? 'local'
            : config('media-processing.storage.sermon_disk', 'public');

        if (! Storage::disk($disk)->exists($sermon->video_file_path)) {
            abort(404, 'Video file not found.');
        }

        if (! str_starts_with($sermon->video_file_path, 'private/')) {
            return redirect()->to((string) $this->storageService->getVideoDeliveryUrl($sermon));
        }

        $path = Storage::disk($disk)->path($sermon->video_file_path);
        $name = basename($sermon->video_file_path);

        return response()->file($path, [
            'Content-Type' => $this->videoContentType($name),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Serve thumbnail image for a sermon
     */
    public function serveThumbnail(Sermon $sermon): BinaryFileResponse|RedirectResponse
    {
        if (! $sermon->thumbnail_file_path) {
            abort(404, 'Thumbnail not found.');
        }

        $authorizationResponse = $this->authorizeAssetAccess($sermon, $sermon->thumbnail_file_path);
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        $this->abortOnUnsafePath($sermon->thumbnail_file_path, 'thumbnail');

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
        $cardThumbnailPath = $sermon->card_thumbnail_file_path;

        if (! $cardThumbnailPath) {
            abort(404, 'Card thumbnail not found.');
        }

        $authorizationResponse = $this->authorizeAssetAccess($sermon, $cardThumbnailPath);
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        $this->abortOnUnsafePath($cardThumbnailPath, 'thumbnail');

        $disk = str_starts_with($cardThumbnailPath, 'private/')
            ? 'local'
            : config('thumbnail-generation.storage.disk', 'public');

        if (! Storage::disk($disk)->exists($cardThumbnailPath)) {
            abort(404, 'Thumbnail file not found.');
        }

        if (! str_starts_with($cardThumbnailPath, 'private/')) {
            $cardThumbnailUrl = $this->storageService->getCardThumbnailUrl($sermon);

            if ($cardThumbnailUrl !== null) {
                return redirect()->to($cardThumbnailUrl);
            }
        }

        return $this->serveStoredThumbnail($cardThumbnailPath);
    }

    private function serveStoredThumbnail(string $thumbnailPath): BinaryFileResponse
    {
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

        return response()->file($path, [
            'Content-Type' => $contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $name),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Authorize access to a sermon asset based on content type and storage path.
     */
    private function authorizeAssetAccess(Sermon $sermon, ?string $path): ?RedirectResponse
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        // 1. Storage-level security: assets in the private/ directory are restricted to administrators.
        if ($path !== null && str_starts_with($path, 'private/')) {
            if ($user?->canAccessAdmin() !== true) {
                abort(403, 'Unauthorized access to private asset.');
            }

            return null;
        }

        // 2. Business logic security: Children's Corner content is restricted via its own policy.
        if ($sermon->content_type === SermonContentType::ChildrensTalk) {
            if ($this->exposurePolicy->canAccessChildrensCorner($user)) {
                return null;
            }

            return redirect()->guest(route('login'));
        }

        return null;
    }

    private function abortOnUnsafePath(string $path, string $type): void
    {
        if (str_contains($path, '..')) {
            abort(404, "Invalid {$type} file path.");
        }
    }

    private function videoContentType(string $name): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => 'video/mp4',
        };
    }
}
