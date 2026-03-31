<?php

declare(strict_types=1);

namespace App\Data;

final class ThumbnailMetadata extends JsonData
{
    /**
     * @param  array<string, mixed>  $videoResolution
     * @param  array<string, mixed>  $thumbnailSizes
     * @param  array<string, int>  $foregroundBounds
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?float $timestamp,
        public readonly ?float $videoDuration,
        public readonly array $videoResolution = [],
        public readonly array $thumbnailSizes = [],
        public readonly ?string $generatedAt = null,
        public readonly ?string $plainThumbnailPath = null,
        public readonly ?string $overlayThumbnailPath = null,
        public readonly ?string $compositionMode = null,
        public readonly ?string $foregroundExtractionMethod = null,
        public readonly array $foregroundBounds = [],
        public readonly ?float $foregroundCoverage = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        return new self(
            timestamp: self::floatOrNull($value['timestamp'] ?? null),
            videoDuration: self::floatOrNull($value['video_duration'] ?? null),
            videoResolution: self::arrayValue($value['video_resolution'] ?? null),
            thumbnailSizes: self::arrayValue($value['thumbnail_sizes'] ?? null),
            generatedAt: self::stringOrNull($value['generated_at'] ?? null),
            plainThumbnailPath: self::stringOrNull($value['plain_thumbnail_path'] ?? null),
            overlayThumbnailPath: self::stringOrNull($value['overlay_thumbnail_path'] ?? null),
            compositionMode: self::stringOrNull($value['composition_mode'] ?? null),
            foregroundExtractionMethod: self::stringOrNull($value['foreground_extraction_method'] ?? null),
            foregroundBounds: self::arrayValue($value['foreground_bounds'] ?? null),
            foregroundCoverage: self::floatOrNull($value['foreground_coverage'] ?? null),
            raw: $value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->timestamp !== null) {
            $data['timestamp'] = $this->timestamp;
        }

        if ($this->videoDuration !== null) {
            $data['video_duration'] = $this->videoDuration;
        }

        if ($this->videoResolution !== []) {
            $data['video_resolution'] = $this->videoResolution;
        }

        if ($this->thumbnailSizes !== []) {
            $data['thumbnail_sizes'] = $this->thumbnailSizes;
        }

        if ($this->generatedAt !== null) {
            $data['generated_at'] = $this->generatedAt;
        }

        if ($this->plainThumbnailPath !== null) {
            $data['plain_thumbnail_path'] = $this->plainThumbnailPath;
        }

        if ($this->overlayThumbnailPath !== null) {
            $data['overlay_thumbnail_path'] = $this->overlayThumbnailPath;
        }

        if ($this->compositionMode !== null) {
            $data['composition_mode'] = $this->compositionMode;
        }

        if ($this->foregroundExtractionMethod !== null) {
            $data['foreground_extraction_method'] = $this->foregroundExtractionMethod;
        }

        if ($this->foregroundBounds !== []) {
            $data['foreground_bounds'] = $this->foregroundBounds;
        }

        if ($this->foregroundCoverage !== null) {
            $data['foreground_coverage'] = $this->foregroundCoverage;
        }

        return $data;
    }
}
