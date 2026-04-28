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
        $authorizationResponse = $this->authorizeAssetAccess($sermon, 'audio', $sermon->audio_file_path);
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        if (! $sermon->audio_file_path) {
            abort(404, 'Audio file not found.');
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
        $authorizationResponse = $this->authorizeAssetAccess($sermon, 'video', $sermon->video_file_path);
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        if (! $sermon->video_file_path) {
            abort(404, 'Video file not found.');
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
        $authorizationResponse = $this->authorizeAssetAccess($sermon, 'thumbnail', $sermon->thumbnail_file_path);
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        if (! $sermon->thumbnail_file_path) {
            abort(404, 'Thumbnail not found.');
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

        $authorizationResponse = $this->authorizeAssetAccess($sermon, 'thumbnail', $cardThumbnailPath);
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        if (! $cardThumbnailPath) {
            abort(404, 'Card thumbnail not found.');
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
     * Authorize access to a sermon asset based on content type and exposure policy.
     *
     * Security: Admins have unrestricted access for review. Children's Talk access
     * is gated by verified email (when not public). Regular sermon video and
     * thumbnail visibility is governed by automated quality assessment and
     * manual visibility overrides.
     */
    private function authorizeAssetAccess(Sermon $sermon, string $assetType, ?string $path): ?RedirectResponse
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        // Security: Administrators are exempt from exposure policies to allow for review.
        if ($user?->canAccessAdmin()) {
            return null;
        }

        // Security: Children's Corner access is gated by verified email (when not public).
        // This check takes precedence over other restrictions to ensure proper login redirection.
        if ($sermon->content_type === SermonContentType::ChildrensTalk) {
            if (! $this->exposurePolicy->canAccessChildrensCorner($user)) {
                return redirect()->guest(route('login'));
            }
        }

        // Security: Private assets are restricted to administrators.
        // Public users should only access assets marked as public (non-private/ path).
        if ($path !== null && str_starts_with($path, 'private/')) {
            abort(404, 'Asset not available.');
        }

        // Visibility checks based on quality assessment and manual overrides.
        // These apply to all sermons, including Children's Talks (Defense in Depth).
        $exposed = match ($assetType) {
            'video' => $this->exposurePolicy->shouldExposeVideo($sermon),
            'thumbnail' => $this->exposurePolicy->shouldExposeThumbnail($sermon),
            default => true,
        };

        if (! $exposed) {
            abort(404, 'Asset not available.');
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
