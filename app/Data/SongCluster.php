<?php

declare(strict_types=1);

namespace App\Data;

final class SongCluster extends JsonData
{
    /**
     * @param  list<float>  $samples
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly float $startEstimate,
        public readonly float $endEstimate,
        public readonly array $samples,
        public readonly ?int $sampleCount = null,
        public readonly ?float $confidence = null,
        public readonly ?float $refinedVisualStart = null,
        public readonly ?float $refinedVisualEnd = null,
        public readonly ?int $denseSampleCount = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $startEstimate = self::floatOrNull($value['start_estimate'] ?? null);
        $endEstimate = self::floatOrNull($value['end_estimate'] ?? null);
        $samples = self::arrayValue($value['samples'] ?? null);

        if ($startEstimate === null || $endEstimate === null || $samples === []) {
            return null;
        }

        return new self(
            startEstimate: $startEstimate,
            endEstimate: $endEstimate,
            samples: array_values(array_map('floatval', $samples)),
            sampleCount: self::intOrNull($value['sample_count'] ?? null),
            confidence: self::floatOrNull($value['confidence'] ?? null),
            refinedVisualStart: self::floatOrNull($value['refined_visual_start'] ?? null),
            refinedVisualEnd: self::floatOrNull($value['refined_visual_end'] ?? null),
            denseSampleCount: self::intOrNull($value['dense_sample_count'] ?? null),
            raw: $value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;
        $data['start_estimate'] = $this->startEstimate;
        $data['end_estimate'] = $this->endEstimate;
        $data['samples'] = $this->samples;
        $data['sample_count'] = $this->sampleCount ?? count($this->samples);

        if ($this->confidence !== null) {
            $data['confidence'] = $this->confidence;
        }

        if ($this->refinedVisualStart !== null) {
            $data['refined_visual_start'] = $this->refinedVisualStart;
        }

        if ($this->refinedVisualEnd !== null) {
            $data['refined_visual_end'] = $this->refinedVisualEnd;
        }

        if ($this->denseSampleCount !== null) {
            $data['dense_sample_count'] = $this->denseSampleCount;
        }

        return $data;
    }
}
