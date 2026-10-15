<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Enums\SermonPublicationState;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Support\MediaAssetPath;
use App\Support\SermonAssetReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Hold what a run published onto a holding disk, as creation now does ({@see MediaAssetPath::holdingDisk()}).
 *
 * Before that rule, a weekly run processed in the follow-up round created its sermon and song
 * clips `published` with no disk recorded, though every byte sat on historic staging (890, 893
 * and 894, 2026-10-15). This applies the creation rule to those rows: quarantined, naming the
 * holding disk. Nothing moves and nothing is deleted; a row is held only when every file it
 * references is on that disk, so it never ends up naming a disk its bytes are not on.
 *
 * Deletion trigger: Delete with the follow-up round's frozen clone.
 */
final class HoldingDiskCustodyRepair
{
    /**
     * @return array{disk: string, sermons: list<Sermon>, song_videos: list<SongVideo>}
     */
    public function inspect(string $processingId): array
    {
        $disk = MediaAssetPath::holdingDisk();

        if ($disk === null) {
            throw new RuntimeException('The sermon disk ['.MediaAssetPath::disk().'] publishes; nothing written to it is held.');
        }

        $run = MediaProcessingLog::query()->where('processing_id', $processingId)->first();

        if (! $run instanceof MediaProcessingLog) {
            throw new RuntimeException("No run [{$processingId}] exists.");
        }

        if ($run->historic_import_operation_id !== null) {
            throw new RuntimeException("Run {$run->id} belongs to a historic operation, whose own custody and promotion hold its output.");
        }

        $sermons = array_values(Sermon::query()
            ->where('livestream_processing_id', $run->processing_id)
            ->where('publication_state', SermonPublicationState::Published)
            ->whereNull('asset_disk')
            ->orderBy('id')
            ->get()
            ->all());

        $songVideos = array_values(SongVideo::query()
            ->whereIn('service_section_id', ServiceSection::query()->select('id')->where('media_processing_log_id', $run->id))
            ->where('publication_state', SermonPublicationState::Published)
            ->whereNull('asset_disk')
            ->orderBy('id')
            ->get()
            ->all());

        $missing = [
            ...array_merge([], ...array_map(fn (Sermon $sermon): array => $this->missingSermonFiles($sermon, $disk), $sermons)),
            ...array_merge([], ...array_map(fn (SongVideo $video): array => $this->missingSongVideoFiles($video, $disk), $songVideos)),
        ];

        if ($missing !== []) {
            throw new RuntimeException("Not every file is on [{$disk}], so nothing was held: ".implode(', ', $missing).'.');
        }

        return ['disk' => $disk, 'sermons' => $sermons, 'song_videos' => $songVideos];
    }

    /**
     * @param  array{disk: string, sermons: list<Sermon>, song_videos: list<SongVideo>}  $inspection
     */
    public function apply(array $inspection): void
    {
        $held = [
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => $inspection['disk'],
        ];

        DB::transaction(function () use ($inspection, $held): void {
            foreach ([...$inspection['sermons'], ...$inspection['song_videos']] as $record) {
                $record->forceFill($held)->save();
            }
        });
    }

    /**
     * @return list<string>
     */
    private function missingSermonFiles(Sermon $sermon, string $disk): array
    {
        $held = (clone $sermon)->forceFill(['asset_disk' => $disk]);

        return $this->missing(
            "sermon {$sermon->id}",
            $disk,
            array_column(SermonAssetReferences::for($held), 'path'),
        );
    }

    /**
     * @return list<string>
     */
    private function missingSongVideoFiles(SongVideo $video, string $disk): array
    {
        return $this->missing("song video {$video->id}", $disk, [(string) $video->video_file_path]);
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function missing(string $label, string $disk, array $paths): array
    {
        $absent = array_filter($paths, static fn (string $path): bool => ! Storage::disk($disk)->exists($path));

        return array_values(array_map(static fn (string $path): string => "{$label} {$path}", $absent));
    }
}
