<?php

declare(strict_types=1);

namespace App\Data;

final class ProcessingManualReviewMetadata extends JsonData
{
    /**
     * @param  list<array{segment_id:int,start_time:float,end_time:float,duration:float}>  $speechSegments
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?string $reasonCode = null,
        public readonly ?string $reasonMessage = null,
        public readonly ?string $flaggedAt = null,
        public readonly array $speechSegments = [],
        public readonly ?int $confirmedSegmentId = null,
        public readonly ?int $confirmedByUserId = null,
        public readonly ?string $confirmedAt = null,
        public readonly array $raw = [],
    ) {}

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $speechSegments = [];

        foreach (self::arrayValue($value['speech_segments'] ?? null) as $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $segmentId = self::intOrNull($segment['segment_id'] ?? null);
            $startTime = self::floatOrNull($segment['start_time'] ?? null);
            $endTime = self::floatOrNull($segment['end_time'] ?? null);
            $duration = self::floatOrNull($segment['duration'] ?? null);

            if ($segmentId === null || $startTime === null || $endTime === null || $duration === null) {
                continue;
            }

            $speechSegments[] = [
                'segment_id' => $segmentId,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
            ];
        }

        return new self(
            status: self::stringOrNull($value['status'] ?? null),
            reasonCode: self::stringOrNull($value['reason_code'] ?? null),
            reasonMessage: self::stringOrNull($value['reason_message'] ?? null),
            flaggedAt: self::stringOrNull($value['flagged_at'] ?? null),
            speechSegments: $speechSegments,
            confirmedSegmentId: self::intOrNull($value['confirmed_segment_id'] ?? null),
            confirmedByUserId: self::intOrNull($value['confirmed_by_user_id'] ?? null),
            confirmedAt: self::stringOrNull($value['confirmed_at'] ?? null),
            raw: $value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->raw;

        if ($this->status !== null) {
            $data['status'] = $this->status;
        }

        if ($this->reasonCode !== null) {
            $data['reason_code'] = $this->reasonCode;
        }

        if ($this->reasonMessage !== null) {
            $data['reason_message'] = $this->reasonMessage;
        }

        if ($this->flaggedAt !== null) {
            $data['flagged_at'] = $this->flaggedAt;
        }

        if ($this->speechSegments !== []) {
            $data['speech_segments'] = $this->speechSegments;
        }

        if ($this->confirmedSegmentId !== null) {
            $data['confirmed_segment_id'] = $this->confirmedSegmentId;
        }

        if ($this->confirmedByUserId !== null) {
            $data['confirmed_by_user_id'] = $this->confirmedByUserId;
        }

        if ($this->confirmedAt !== null) {
            $data['confirmed_at'] = $this->confirmedAt;
        }

        return $data;
    }
}
