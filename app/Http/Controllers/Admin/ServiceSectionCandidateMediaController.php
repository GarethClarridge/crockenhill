<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceSectionPublicationStatus;
use App\Http\Controllers\Controller;
use App\Models\ServiceSection;
use App\Traits\HandlesSafePaths;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class ServiceSectionCandidateMediaController extends Controller
{
    use HandlesSafePaths;

    public function serveAudio(ServiceSection $serviceSection): BinaryFileResponse
    {
        return $this->serveAsset(
            $serviceSection,
            $serviceSection->extracted_audio_path,
            'audio/mpeg',
        );
    }

    public function serveVideo(ServiceSection $serviceSection): BinaryFileResponse
    {
        return $this->serveAsset(
            $serviceSection,
            $serviceSection->extracted_video_path,
            'video/mp4',
        );
    }

    private function serveAsset(
        ServiceSection $serviceSection,
        ?string $path,
        string $contentType,
    ): BinaryFileResponse {
        abort_if(
            $serviceSection->publication_status === ServiceSectionPublicationStatus::PUBLISHED
                || $serviceSection->published_sermon_id !== null,
            404,
            'Candidate media is no longer available.',
        );

        if (! is_string($path) || $path === '') {
            abort(404, 'Candidate media not found.');
        }

        $this->abortIfUnsafe($path, 'candidate media');

        $disk = $serviceSection->extractedAssetDisk($path);

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'Candidate media not found.');
        }

        $absolutePath = Storage::disk($disk)->path($path);

        $response = response()->file($absolutePath, [
            'Content-Type' => $contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                basename($path),
            ),
        ]);

        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
