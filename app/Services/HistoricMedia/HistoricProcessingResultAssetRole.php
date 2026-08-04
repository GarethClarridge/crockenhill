<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * The Bundle A asset role vocabulary.
 *
 * Roles are the contract between the exporter, which names every logical owner
 * of a physical asset, and the persister, which allocates a destination for each
 * one and writes the remapped path back onto the owning record. The two sides
 * previously built and parsed these strings independently, so a divergence in a
 * single fallback silently stranded an asset at its staging path. Every role is
 * now constructed and parsed here.
 */
final class HistoricProcessingResultAssetRole
{
    /** @var list<string> */
    public const RUN_FIELDS = [
        'audio_file_path',
        'video_file_path',
        'transcript_file_path',
        'rms_log_path',
    ];

    /** @var list<string> */
    public const PUBLICATION_FIELDS = [
        'audio_file_path',
        'video_file_path',
        'transcript_file_path',
        'thumbnail_file_path',
    ];

    /** @var list<string> */
    public const THUMBNAIL_METADATA_FIELDS = [
        'plain_thumbnail_path',
        'card_thumbnail_path',
        'overlay_thumbnail_path',
    ];

    /** @var list<string> */
    public const THUMBNAIL_CANDIDATE_FIELDS = [
        'plain_path',
        'card_path',
        'overlay_path',
    ];

    /** @var list<string> */
    public const SECTION_FIELDS = [
        'extracted_video_path',
        'extracted_audio_path',
    ];

    public const SONG_VIDEO_FIELD = 'video_file_path';

    private const DEFAULT_ARTIFACT_KIND = 'artifact';

    /**
     * Every path-shaped field the portable graph is allowed to carry.
     *
     * @return list<string>
     */
    public static function allowedPathFields(): array
    {
        return array_values(array_unique([
            ...self::RUN_FIELDS,
            ...self::PUBLICATION_FIELDS,
            ...self::THUMBNAIL_METADATA_FIELDS,
            ...self::THUMBNAIL_CANDIDATE_FIELDS,
            ...self::SECTION_FIELDS,
        ]));
    }

    public static function run(string $field): string
    {
        return "run_{$field}";
    }

    /**
     * Service artifacts are identified by their recorded content hash rather
     * than their path, so the role survives the path remap that import applies.
     *
     * @param  array<string, mixed>  $artifact
     */
    public static function serviceArtifact(array $artifact): string
    {
        $path = is_string($artifact['path'] ?? null) ? $artifact['path'] : '';
        $sha256 = is_string($artifact['sha256'] ?? null) && $artifact['sha256'] !== ''
            ? $artifact['sha256']
            : hash('sha256', $path);

        return 'service_artifact:'.self::serviceArtifactKind($artifact['kind'] ?? null).":{$sha256}";
    }

    public static function serviceArtifactKind(mixed $kind): string
    {
        return is_string($kind) && $kind !== '' ? $kind : self::DEFAULT_ARTIFACT_KIND;
    }

    public static function publicationField(string $publicationKey, string $field): string
    {
        return "publication:{$publicationKey}:{$field}";
    }

    public static function publicationThumbnailMetadata(string $publicationKey, string $field): string
    {
        return "publication:{$publicationKey}:thumbnail_metadata:{$field}";
    }

    public static function publicationThumbnailCandidate(string $publicationKey, int $index, string $field): string
    {
        return "publication:{$publicationKey}:thumbnail_candidate:{$index}:{$field}";
    }

    public static function section(string $sectionKey, string $field): string
    {
        return "section:{$sectionKey}:{$field}";
    }

    public static function songVideo(string $sectionKey): string
    {
        return "song_video:{$sectionKey}:".self::SONG_VIDEO_FIELD;
    }

    /**
     * @return (
     *     array{publication_key: string, type: 'field', field: string}
     *     | array{publication_key: string, type: 'thumbnail_metadata', field: string}
     *     | array{publication_key: string, type: 'thumbnail_candidate', field: string, candidate_index: int}
     * )|null
     */
    public static function parsePublication(string $role): ?array
    {
        if (! str_starts_with($role, 'publication:')) {
            return null;
        }

        $value = Str::after($role, 'publication:');

        if (str_contains($value, ':thumbnail_metadata:')) {
            $field = Str::after($value, ':thumbnail_metadata:');

            if (! in_array($field, self::THUMBNAIL_METADATA_FIELDS, true)) {
                throw new RuntimeException("Unknown thumbnail metadata asset role {$role}.");
            }

            return [
                'publication_key' => Str::before($value, ':thumbnail_metadata:'),
                'type' => 'thumbnail_metadata',
                'field' => $field,
            ];
        }

        if (str_contains($value, ':thumbnail_candidate:')) {
            $candidateValue = Str::after($value, ':thumbnail_candidate:');
            $candidateIndex = Str::before($candidateValue, ':');
            $field = Str::after($candidateValue, ':');

            if (! ctype_digit($candidateIndex) || ! in_array($field, self::THUMBNAIL_CANDIDATE_FIELDS, true)) {
                throw new RuntimeException("Unknown thumbnail candidate asset role {$role}.");
            }

            return [
                'publication_key' => Str::before($value, ':thumbnail_candidate:'),
                'type' => 'thumbnail_candidate',
                'field' => $field,
                'candidate_index' => (int) $candidateIndex,
            ];
        }

        $field = Str::afterLast($value, ':');

        if (! in_array($field, self::PUBLICATION_FIELDS, true)) {
            throw new RuntimeException("Unknown publication asset role {$role}.");
        }

        return [
            'publication_key' => Str::beforeLast($value, ':'),
            'type' => 'field',
            'field' => $field,
        ];
    }

    /** @return array{section_key: string, field: string}|null */
    public static function parseSongVideo(string $role): ?array
    {
        if (! str_starts_with($role, 'song_video:')) {
            return null;
        }

        $value = Str::after($role, 'song_video:');
        $field = Str::afterLast($value, ':');

        if ($field !== self::SONG_VIDEO_FIELD) {
            throw new RuntimeException("Unknown song video asset role {$role}.");
        }

        return [
            'section_key' => Str::beforeLast($value, ':'),
            'field' => self::SONG_VIDEO_FIELD,
        ];
    }

    /** @return array{section_key: string, field: string}|null */
    public static function parseSection(string $role): ?array
    {
        if (! str_starts_with($role, 'section:')) {
            return null;
        }

        $value = Str::after($role, 'section:');
        $field = Str::afterLast($value, ':');

        if (! in_array($field, self::SECTION_FIELDS, true)) {
            throw new RuntimeException("Unknown section asset role {$role}.");
        }

        return [
            'section_key' => Str::beforeLast($value, ':'),
            'field' => $field,
        ];
    }

    public static function thumbnailVariant(string $field): string
    {
        return match ($field) {
            'plain_thumbnail_path', 'plain_path' => 'plain',
            'card_thumbnail_path', 'card_path' => 'card',
            'overlay_thumbnail_path', 'overlay_path' => 'overlay',
            default => throw new RuntimeException("Unknown thumbnail variant {$field}."),
        };
    }
}
