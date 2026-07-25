<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ServiceSectionPublicationStatus;
use App\Http\Controllers\Controller;
use App\Models\ServiceSection;
use App\Support\Path;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

class ServiceSectionCandidateMediaController extends Controller
{
    public function serveAudio(ServiceSection $serviceSection): RedirectResponse
    {
        return $this->serveAsset($serviceSection, $serviceSection->extracted_audio_path);
    }

    public function serveVideo(ServiceSection $serviceSection): RedirectResponse
    {
        return $this->serveAsset($serviceSection, $serviceSection->extracted_video_path);
    }

    /**
     * Authorise, then redirect to the candidate's storage URL.
     *
     * Candidates live on the sermon disk rather than in a local private
     * directory, so streaming them through the application would mean pulling
     * every byte back out of the object store on each request. The admin guard
     * on the route and the published-section check below stay the entry gate,
     * and the keys carry a component that cannot be derived from the section id
     * so they cannot be walked (see PrepareSectionPublicationCandidates).
     */
    private function serveAsset(ServiceSection $serviceSection, ?string $path): RedirectResponse
    {
        abort_if(
            $serviceSection->publication_status === ServiceSectionPublicationStatus::Published
                || $serviceSection->published_sermon_id !== null,
            404,
            'Candidate media is no longer available.',
        );

        if (! is_string($path) || $path === '' || Path::isUnsafe($path)) {
            abort(404, 'Candidate media not found.');
        }

        $disk = $serviceSection->extractedAssetDisk();

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'Candidate media not found.');
        }

        return redirect()
            ->to(Storage::disk($disk)->url($path))
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
