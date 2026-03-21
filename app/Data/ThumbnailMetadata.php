<?php

declare(strict_types=1);

namespace App\Data;

final class ThumbnailMetadata extends JsonData
{
    /**
     * @param  array<string, mixed>  $videoResolution
     * @param  array<string, mixed>  $thumbnailSizes
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

        return $data;
    }
}
