<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Models\User;
use App\Services\Media\Audio\SermonTranscriptReader;
use App\Services\SafeMarkdownRenderer;
use App\Services\Sermon\SermonExposurePolicy;
use App\Services\Sermon\SermonStorageService;
use App\Support\Path;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SermonAssetController extends Controller
{
    public function __construct(
        private readonly SermonStorageService $storageService,
        private readonly SermonExposurePolicy $exposurePolicy,
        private readonly SermonTranscriptReader $transcriptReader,
        private readonly SafeMarkdownRenderer $markdownRenderer,
    ) {}

    /**
     * Serve the rendered HTML for a sermon transcript so the detail page can
     * fetch it lazily instead of embedding the full markdown render up front.
     */
    public function serveTranscript(Sermon $sermon): Response|RedirectResponse
    {
        $authorizationResponse = $this->authorizeAssetAccess($sermon, 'transcript');
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        $transcript = $this->transcriptReader->read($sermon);

        if (blank($transcript)) {
            abort(404, 'Transcript not found.');
        }

        $html = $this->markdownRenderer->convert($transcript);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Serve audio file for a sermon
     */
    public function serveAudio(Sermon $sermon): RedirectResponse|StreamedResponse
    {
        $authorizationResponse = $this->authorizeAssetAccess($sermon, 'audio');
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        if (! $sermon->audio_file_path) {
            abort(404, 'Audio file not found.');
        }

        if (Path::isUnsafe($sermon->audio_file_path)) {
            abort(404, 'Invalid audio file path.');
        }

        $fileInfo = $this->storageService->getSermonFileInfo($sermon);

        if (! Storage::disk($fileInfo['disk'])->exists($fileInfo['path'])) {
            abort(404, 'Audio file not found.');
        }

        if (! $this->exposurePolicy->isWholeContentPublic($sermon)) {
            return Storage::disk($fileInfo['disk'])->download($fileInfo['path']);
        }

        return redirect()->to($this->storageService->getPublicUrl($sermon));
    }

    public function serveVideo(Sermon $sermon): RedirectResponse|StreamedResponse
    {
        $authorizationResponse = $this->authorizeAssetAccess($sermon, 'video');
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        if (! $sermon->video_file_path) {
            abort(404, 'Video file not found.');
        }

        if (Path::isUnsafe($sermon->video_file_path)) {
            abort(404, 'Invalid video file path.');
        }

        $disk = $this->assetDisk($sermon, (string) config('media-processing.storage.sermon_disk', 'public'));

        if (! Storage::disk($disk)->exists($sermon->video_file_path)) {
            abort(404, 'Video file not found.');
        }

        if (! $this->exposurePolicy->isWholeContentPublic($sermon)) {
            return Storage::disk($disk)->download($sermon->video_file_path);
        }

        return $this->redirectToAsset(
            $this->storageService->getVideoDeliveryUrl($sermon),
            'Video file not found.',
        );
    }

    /**
     * Serve thumbnail image for a sermon
     */
    public function serveThumbnail(Sermon $sermon): RedirectResponse|StreamedResponse
    {
        $authorizationResponse = $this->authorizeAssetAccess($sermon, 'thumbnail');
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        if (! $sermon->thumbnail_file_path) {
            abort(404, 'Thumbnail not found.');
        }

        if (Path::isUnsafe($sermon->thumbnail_file_path)) {
            abort(404, 'Invalid thumbnail file path.');
        }

        $disk = $this->assetDisk($sermon, (string) config('thumbnail-generation.storage.disk', 'public'));

        if (! Storage::disk($disk)->exists($sermon->thumbnail_file_path)) {
            abort(404, 'Thumbnail file not found.');
        }

        if (! $this->exposurePolicy->isWholeContentPublic($sermon)) {
            return Storage::disk($disk)->download($sermon->thumbnail_file_path);
        }

        return $this->redirectToAsset($this->storageService->getThumbnailUrl($sermon));
    }

    public function servePlainThumbnail(Sermon $sermon): RedirectResponse|StreamedResponse
    {
        $authorizationResponse = $this->authorizeAssetAccess($sermon, 'plain_thumbnail');
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        $plainThumbnailPath = $sermon->plain_thumbnail_file_path;

        if (! $plainThumbnailPath) {
            abort(404, 'Plain thumbnail not found.');
        }

        if (Path::isUnsafe($plainThumbnailPath)) {
            abort(404, 'Invalid thumbnail file path.');
        }

        $disk = $this->assetDisk($sermon, (string) config('thumbnail-generation.storage.disk', 'public'));

        if (! Storage::disk($disk)->exists($plainThumbnailPath)) {
            abort(404, 'Thumbnail file not found.');
        }

        if (! $this->exposurePolicy->isWholeContentPublic($sermon)) {
            return Storage::disk($disk)->download($plainThumbnailPath);
        }

        return $this->redirectToAsset($this->storageService->getPlainThumbnailUrl($sermon));
    }

    /**
     * Serve the thumbnail variant intended for compact UI cards.
     */
    public function serveCardThumbnail(Sermon $sermon): RedirectResponse|StreamedResponse
    {
        $authorizationResponse = $this->authorizeAssetAccess($sermon, 'card_thumbnail');
        if ($authorizationResponse instanceof RedirectResponse) {
            return $authorizationResponse;
        }

        $cardThumbnailPath = $sermon->card_thumbnail_file_path;

        if (! $cardThumbnailPath) {
            abort(404, 'Card thumbnail not found.');
        }

        if (Path::isUnsafe($cardThumbnailPath)) {
            abort(404, 'Invalid thumbnail file path.');
        }

        $disk = $this->assetDisk($sermon, (string) config('thumbnail-generation.storage.disk', 'public'));

        if (! Storage::disk($disk)->exists($cardThumbnailPath)) {
            abort(404, 'Thumbnail file not found.');
        }

        if (! $this->exposurePolicy->isWholeContentPublic($sermon)) {
            return Storage::disk($disk)->download($cardThumbnailPath);
        }

        return $this->redirectToAsset($this->storageService->getCardThumbnailUrl($sermon));
    }

    /**
     * Redirect to an asset's public URL, 404ing if one could not be resolved.
     *
     * Every asset now lives on a public disk, so these routes exist to authorise
     * the request and then hand off — the streaming branch they used to fall back
     * to went with the `private/` prefix.
     */
    private function redirectToAsset(?string $url, string $notFoundMessage = 'Thumbnail file not found.'): RedirectResponse
    {
        if ($url === null) {
            abort(404, $notFoundMessage);
        }

        return redirect()->to($url);
    }

    /**
     * Authorize access to a sermon asset based on content type and exposure policy.
     *
     * Security: Admins have unrestricted access for review. Children's Talk access
     * is gated by verified email (when not public). Regular sermon video and
     * thumbnail visibility is governed by automated quality assessment and
     * manual visibility overrides.
     */
    private function authorizeAssetAccess(Sermon $sermon, string $assetType): ?RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::user();

        // Security: Administrators are exempt from exposure policies to allow for review.
        if ($user?->canAccessAdmin()) {
            return null;
        }

        if (! $this->exposurePolicy->isWholeContentPublic($sermon)) {
            abort(404, 'Asset not available.');
        }

        // Security: Children's Corner access is gated by verified email (when not public).
        // This check takes precedence over other restrictions to ensure proper login redirection.
        if ($sermon->content_type === SermonContentType::ChildrensTalk) {
            if (! $this->exposurePolicy->canAccessChildrensCorner($user)) {
                return redirect()->guest(route('login'));
            }
        }

        // Visibility checks based on quality assessment and manual overrides.
        // These apply to all sermons, including Children's Talks (Defense in Depth).
        $exposed = match ($assetType) {
            'video' => $this->exposurePolicy->shouldExposeVideo($sermon),
            'thumbnail', 'plain_thumbnail', 'card_thumbnail' => $this->exposurePolicy->shouldExposeThumbnail($sermon),
            default => true,
        };

        if (! $exposed) {
            abort(404, 'Asset not available.');
        }

        return null;
    }

    private function assetDisk(Sermon $sermon, string $fallback): string
    {
        return filled($sermon->asset_disk) ? (string) $sermon->asset_disk : $fallback;
    }
}
