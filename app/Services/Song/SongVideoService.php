<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricStagingUrlGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Service for managing the lifecycle of song video recordings.
 *
 * This service handles the storage, feature selection, and cleanup of video files
 * associated with individual songs. It supports both manual uploads from the admin
 * interface and automated extractions from church service recordings.
 */
class SongVideoService
{
    /**
     * Get the publicly accessible URL for a song video.
     *
     * @param  SongVideo  $video  The video model to get the URL for
     * @return string The absolute URL to the video file
     */
    public function getVideoUrl(SongVideo $video): string
    {
        $disk = $this->assetDisk($video);
        HistoricStagingUrlGuard::assertAllowed($disk);

        return Storage::disk($disk)->url($video->video_file_path);
    }

    /**
     * Retrieve the currently featured or primary video for a given song.
     *
     * @param  Song  $song  The song model
     * @return SongVideo|null The featured video if one exists
     */
    public function getDisplayVideoForSong(Song $song): ?SongVideo
    {
        return $song->displayVideo();
    }

    /**
     * Set a specific video as the featured video for its song.
     *
     * Only one video can be featured per song at a time. This method ensures
     * that any existing featured video for the same song is unfeatured.
     *
     * @param  SongVideo  $video  The video to feature
     */
    public function featureVideo(SongVideo $video): void
    {
        DB::transaction(function () use ($video): void {
            // Lock the song row to serialize concurrent feature operations,
            // preventing two simultaneous requests from both setting is_featured = true.
            Song::query()->lockForUpdate()->find($video->song_id);

            SongVideo::query()
                ->where('song_id', $video->song_id)
                ->where('is_featured', true)
                ->where('id', '!=', $video->id)
                ->update(['is_featured' => false]);

            $video->is_featured = true;
            $video->save();
        });
    }

    /**
     * Remove the featured status from a video.
     *
     * @param  SongVideo  $video  The video to unfeature
     */
    public function unfeatureVideo(SongVideo $video): void
    {
        $video->is_featured = false;
        $video->save();
    }

    /**
     * Delete a song video record and its corresponding file from storage.
     *
     * If the video was linked to a service section, that section is reset
     * to allow for potential re-extraction in the future.
     *
     * @param  SongVideo  $video  The video to delete
     */
    public function deleteVideo(SongVideo $video): void
    {
        $sectionId = $video->service_section_id;

        Storage::disk($this->assetDisk($video))->delete($video->video_file_path);
        $video->delete();

        if ($sectionId !== null) {
            $this->resetLinkedSectionForReExtraction($sectionId);
        }
    }

    /**
     * Create a new song video from a manual file upload.
     *
     * @param  Song  $song  The song the video belongs to
     * @param  UploadedFile  $file  The uploaded video file
     * @return SongVideo The newly created video record
     */
    public function createFromUpload(Song $song, UploadedFile $file): SongVideo
    {
        $storagePath = 'sermons/songs/'.$song->id.'/'.Str::ulid()->toBase32().'.mp4';

        Storage::disk($this->sermonDisk())->putFileAs(
            dirname($storagePath),
            $file,
            basename($storagePath),
        );

        return SongVideo::query()->create([
            'song_id' => $song->id,
            'video_file_path' => $storagePath,
            'is_featured' => false,
        ]);
    }

    /**
     * Create a song video record from an automated extraction process.
     *
     * Links the video to the specific service section and processing run
     * from which it was extracted.
     *
     * @param  ServiceSection  $section  The service section the video was extracted from
     * @param  string  $videoPath  The path to the extracted video file in storage
     * @return SongVideo The newly created video record
     *
     * @throws \RuntimeException If the section does not have a linked song or church service item
     */
    public function createFromExtraction(ServiceSection $section, string $videoPath): SongVideo
    {
        $item = $section->churchServiceItem;
        if (! $item instanceof ChurchServiceItem || $item->song_id === null) {
            throw new \RuntimeException('createFromExtraction requires a section with a linked ChurchServiceItem and song_id');
        }

        $processingLog = $section->processingLog;
        $churchService = $processingLog->churchService;

        $attributes = [
            'song_id' => $item->song_id,
            'service_section_id' => $section->id,
            'church_service_id' => $processingLog->church_service_id,
            'video_file_path' => $videoPath,
            'duration' => $section->duration,
            'recorded_date' => $churchService?->date,
            'is_featured' => false,
        ];

        if ($processingLog->historic_import_operation_id !== null) {
            $attributes['publication_state'] = SermonPublicationState::Quarantined;
            $attributes['asset_disk'] = $this->sermonDisk();
            $attributes['historic_import_operation_id'] = $processingLog->historic_import_operation_id;
        }

        return SongVideo::query()->create($attributes);
    }

    private function resetLinkedSectionForReExtraction(int $sectionId): void
    {
        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            return;
        }

        if ($section->publication_status !== ServiceSectionPublicationStatus::Published) {
            return;
        }

        $section->publication_status = ServiceSectionPublicationStatus::NotApplicable;
        $section->published_at = null;
        $section->published_sermon_id = null;
        $section->save();
    }

    private function sermonDisk(): string
    {
        return (string) config('media-processing.storage.sermon_disk', config('filesystems.default', 'local'));
    }

    private function assetDisk(SongVideo $video): string
    {
        return filled($video->asset_disk) ? (string) $video->asset_disk : $this->sermonDisk();
    }
}
