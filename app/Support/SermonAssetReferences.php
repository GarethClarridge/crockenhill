<?php

declare(strict_types=1);

namespace App\Support;

use App\Data\ThumbnailMetadata;
use App\Models\Sermon;

/**
 * The single enumeration of every asset a sermon row references, paired with the
 * disk that kind is configured to live on.
 *
 * This exists because two separate readers of the same set is how the disk
 * migration stayed invisible for months. `audit:sermon-assets` reports what is
 * missing and `media:restore-stranded-thumbnails` copies it back, and the second
 * can only be verified by the first if both agree, asset for asset, on what the
 * thumbnail family contains. A candidate path enumerated by one and not the
 * other would show up as a restore that never closes the audit's gap.
 */
final class SermonAssetReferences
{
    /**
     * The thumbnail family: every kind whose disk is
     * `thumbnail-generation.storage.disk` rather than a sermon-media disk.
     *
     * @var list<string>
     */
    public const array THUMBNAIL_KINDS = [
        'thumbnail',
        'plain_thumbnail',
        'card_thumbnail',
        'overlay_thumbnail',
        'candidate_plain',
        'candidate_card',
        'candidate_overlay',
    ];

    /**
     * Every non-empty asset reference on the sermon row.
     *
     * @return list<array{kind: string, disk: string, path: string}>
     */
    public static function for(Sermon $sermon): array
    {
        $sermonDisk = self::sermonDisk();
        $transcriptDisk = self::transcriptDisk();
        $thumbnailDisk = self::thumbnailDisk();

        $assets = [
            ['audio', $sermonDisk, $sermon->audio_file_path],
            ['video', $sermonDisk, $sermon->video_file_path],
            ['transcript', $transcriptDisk, $sermon->transcript_file_path],
            ['thumbnail', $thumbnailDisk, $sermon->thumbnail_file_path],
        ];

        $metadata = $sermon->thumbnail_metadata;

        if ($metadata instanceof ThumbnailMetadata) {
            $assets[] = ['plain_thumbnail', $thumbnailDisk, $metadata->plainThumbnailPath];
            $assets[] = ['card_thumbnail', $thumbnailDisk, $metadata->cardThumbnailPath];
            $assets[] = ['overlay_thumbnail', $thumbnailDisk, $metadata->overlayThumbnailPath];

            foreach ($metadata->thumbnailCandidates as $candidate) {
                $assets[] = ['candidate_plain', $thumbnailDisk, $candidate['plain_path']];
                $assets[] = ['candidate_card', $thumbnailDisk, $candidate['card_path'] ?? null];
                $assets[] = ['candidate_overlay', $thumbnailDisk, $candidate['overlay_path'] ?? null];
            }
        }

        $references = [];

        foreach ($assets as [$kind, $disk, $path]) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $references[] = ['kind' => $kind, 'disk' => $disk, 'path' => $path];
        }

        return $references;
    }

    /**
     * The thumbnail-family references only, in the same order as {@see self::for()}.
     *
     * @return list<array{kind: string, disk: string, path: string}>
     */
    public static function thumbnailsFor(Sermon $sermon): array
    {
        return array_values(array_filter(
            self::for($sermon),
            fn (array $reference): bool => in_array($reference['kind'], self::THUMBNAIL_KINDS, true),
        ));
    }

    public static function sermonDisk(): string
    {
        return (string) config('media-processing.storage.sermon_disk', 'public');
    }

    public static function transcriptDisk(): string
    {
        return (string) config('media-processing.storage.transcript_disk', self::sermonDisk());
    }

    public static function thumbnailDisk(): string
    {
        return (string) config('thumbnail-generation.storage.disk', 'public');
    }

    /**
     * The columns {@see self::for()} reads, so callers can select narrowly
     * without the enumeration silently falling back to unloaded attributes.
     *
     * @return list<string>
     */
    public static function selectColumns(): array
    {
        return [
            'audio_file_path',
            'video_file_path',
            'transcript_file_path',
            'thumbnail_file_path',
            'thumbnail_metadata',
        ];
    }
}
