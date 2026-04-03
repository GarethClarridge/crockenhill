<?php

declare(strict_types=1);

namespace App\Data;

final class ThumbnailMetadata extends JsonData
{
    /**
     * @param  array<string, mixed>  $videoResolution
     * @param  array<string, mixed>  $thumbnailSizes
     * @param  list<array{
     *     id: string,
     *     timestamp: float,
     *     score: float,
     *     plain_path: string,
     *     card_path?: string|null,
     *     overlay_path?: string|null,
     *     composition_mode?: string|null,
     *     foreground_extraction_method?: string|null,
     *     foreground_bounds?: array<string, int>,
     *     foreground_coverage?: float|null
     * }>  $thumbnailCandidates
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
        public readonly ?string $cardThumbnailPath = null,
        public readonly ?string $overlayThumbnailPath = null,
        public readonly ?string $selectedThumbnailCandidateId = null,
        public readonly array $thumbnailCandidates = [],
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
            cardThumbnailPath: self::stringOrNull($value['card_thumbnail_path'] ?? null),
            overlayThumbnailPath: self::stringOrNull($value['overlay_thumbnail_path'] ?? null),
            selectedThumbnailCandidateId: self::stringOrNull($value['selected_thumbnail_candidate_id'] ?? null),
            thumbnailCandidates: self::candidateList($value['thumbnail_candidates'] ?? null),
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

        $this->overlayNullable($data, 'timestamp', $this->timestamp);
        $this->overlayNullable($data, 'video_duration', $this->videoDuration);
        $this->overlayArray($data, 'video_resolution', $this->videoResolution);
        $this->overlayArray($data, 'thumbnail_sizes', $this->thumbnailSizes);
        $this->overlayNullable($data, 'generated_at', $this->generatedAt);
        $this->overlayNullable($data, 'plain_thumbnail_path', $this->plainThumbnailPath);
        $this->overlayNullable($data, 'card_thumbnail_path', $this->cardThumbnailPath);
        $this->overlayNullable($data, 'overlay_thumbnail_path', $this->overlayThumbnailPath);
        $this->overlayNullable($data, 'selected_thumbnail_candidate_id', $this->selectedThumbnailCandidateId);
        $this->overlayArray($data, 'thumbnail_candidates', $this->thumbnailCandidates);
        $this->overlayNullable($data, 'composition_mode', $this->compositionMode);
        $this->overlayNullable($data, 'foreground_extraction_method', $this->foregroundExtractionMethod);
        $this->overlayArray($data, 'foreground_bounds', $this->foregroundBounds);
        $this->overlayNullable($data, 'foreground_coverage', $this->foregroundCoverage);

        return $data;
    }

    /**
     * Write the key when the value is non-null OR when the key already existed
     * in $raw (meaning a previously-set value is being explicitly cleared).
     *
     * @param  array<string, mixed>  $data
     */
    private function overlayNullable(array &$data, string $key, mixed $value): void
    {
        if ($value !== null || array_key_exists($key, $this->raw)) {
            $data[$key] = $value;
        }
    }

    /**
     * Write the key when the array is non-empty OR when the key already existed in $raw.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int|string, mixed>  $value
     */
    private function overlayArray(array &$data, string $key, array $value): void
    {
        if ($value !== [] || array_key_exists($key, $this->raw)) {
            $data[$key] = $value;
        }
    }

    /**
     * @return array{
     *     id: string,
     *     timestamp: float,
     *     score: float,
     *     plain_path: string,
     *     card_path?: string|null,
     *     overlay_path?: string|null,
     *     composition_mode?: string|null,
     *     foreground_extraction_method?: string|null,
     *     foreground_bounds?: array<string, int>,
     *     foreground_coverage?: float|null
     * }|null
     */
    public function findCandidate(string $candidateId): ?array
    {
        foreach ($this->thumbnailCandidates as $candidate) {
            if ($candidate['id'] === $candidateId) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     id: string,
     *     timestamp: float,
     *     score: float,
     *     plain_path: string,
     *     card_path?: string|null,
     *     overlay_path?: string|null,
     *     composition_mode?: string|null,
     *     foreground_extraction_method?: string|null,
     *     foreground_bounds?: array<string, int>,
     *     foreground_coverage?: float|null
     * }|null
     */
    public function selectedCandidate(): ?array
    {
        if ($this->selectedThumbnailCandidateId === null) {
            return null;
        }

        return $this->findCandidate($this->selectedThumbnailCandidateId);
    }

    /**
     * @return list<array{
     *     id: string,
     *     timestamp: float,
     *     score: float,
     *     plain_path: string,
     *     card_path?: string|null,
     *     overlay_path?: string|null,
     *     composition_mode?: string|null,
     *     foreground_extraction_method?: string|null,
     *     foreground_bounds?: array<string, int>,
     *     foreground_coverage?: float|null
     * }>
     */
    private static function candidateList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $candidates = [];

        foreach ($value as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $id = self::stringOrNull($candidate['id'] ?? null);
            $overlayPath = self::stringOrNull($candidate['overlay_path'] ?? null);
            $plainPath = self::stringOrNull($candidate['plain_path'] ?? null);
            $cardPath = self::stringOrNull($candidate['card_path'] ?? null);
            $timestamp = self::floatOrNull($candidate['timestamp'] ?? null);
            $score = self::floatOrNull($candidate['score'] ?? null);
            $compositionMode = self::stringOrNull($candidate['composition_mode'] ?? null);
            $foregroundExtractionMethod = self::stringOrNull($candidate['foreground_extraction_method'] ?? null);
            $foregroundBounds = self::arrayValue($candidate['foreground_bounds'] ?? null);
            $foregroundCoverage = self::floatOrNull($candidate['foreground_coverage'] ?? null);

            if ($id === null || $plainPath === null || $timestamp === null || $score === null) {
                continue;
            }

            $normalizedCandidate = [
                'id' => $id,
                'timestamp' => $timestamp,
                'score' => $score,
                'plain_path' => $plainPath,
            ];

            if ($cardPath !== null) {
                $normalizedCandidate['card_path'] = $cardPath;
            }

            if ($overlayPath !== null) {
                $normalizedCandidate['overlay_path'] = $overlayPath;
            }

            if ($compositionMode !== null) {
                $normalizedCandidate['composition_mode'] = $compositionMode;
            }

            if ($foregroundExtractionMethod !== null) {
                $normalizedCandidate['foreground_extraction_method'] = $foregroundExtractionMethod;
            }

            if ($foregroundBounds !== []) {
                $normalizedCandidate['foreground_bounds'] = $foregroundBounds;
            }

            if ($foregroundCoverage !== null) {
                $normalizedCandidate['foreground_coverage'] = $foregroundCoverage;
            }

            $candidates[] = $normalizedCandidate;
        }

        return $candidates;
    }
}
