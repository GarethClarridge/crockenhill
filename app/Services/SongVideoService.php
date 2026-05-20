<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ServiceSectionPublicationStatus;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SongVideoService
{
    public function getVideoUrl(SongVideo $video): string
    {
        return Storage::disk($this->sermonDisk())->url($video->video_file_path);
    }

    public function getDisplayVideoForSong(Song $song): ?SongVideo
    {
        return $song->displayVideo();
    }

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

    public function unfeatureVideo(SongVideo $video): void
    {
        $video->is_featured = false;
        $video->save();
    }

    public function deleteVideo(SongVideo $video): void
    {
        $sectionId = $video->service_section_id;

        Storage::disk($this->sermonDisk())->delete($video->video_file_path);
        $video->delete();

        if ($sectionId !== null) {
            $this->resetLinkedSectionForReExtraction($sectionId);
        }
    }

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

    public function createFromExtraction(ServiceSection $section, string $videoPath): SongVideo
    {
        $item = $section->churchServiceItem;
        if (! $item instanceof ChurchServiceItem || $item->song_id === null) {
            throw new \RuntimeException('createFromExtraction requires a section with a linked ChurchServiceItem and song_id');
        }

        $processingLog = $section->processingLog;
        $churchService = $processingLog->churchService;

        return SongVideo::query()->create([
            'song_id' => $item->song_id,
            'service_section_id' => $section->id,
            'church_service_id' => $processingLog->church_service_id,
            'video_file_path' => $videoPath,
            'duration' => $section->duration,
            'recorded_date' => $churchService?->date,
            'is_featured' => false,
        ]);
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
}
