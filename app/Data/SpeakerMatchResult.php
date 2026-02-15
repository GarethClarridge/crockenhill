<?php

namespace App\Data;

use App\Models\SpeakerProfile;
use Spatie\LaravelData\Data;

class SpeakerMatchResult extends Data
{
    /**
     * @param  array<int, float>  $allScores
     */
    public function __construct(
        public readonly bool $matched,
        public readonly bool $errored = false,
        public readonly ?int $matchedProfileId = null,
        public readonly ?int $matchedPreacherId = null,
        public readonly ?string $matchedPreacherName = null,
        public readonly ?float $topScore = null,
        public readonly ?float $secondScore = null,
        public readonly ?float $margin = null,
        public readonly array $allScores = [],
        public readonly ?string $reason = null,
    ) {}

    /**
     * @param  array<int, float>  $allScores
     */
    public static function matched(
        SpeakerProfile $profile,
        float $topScore,
        ?float $secondScore,
        array $allScores,
    ): self {
        return new self(
            matched: true,
            errored: false,
            matchedProfileId: $profile->id,
            matchedPreacherId: $profile->preacher_id,
            matchedPreacherName: $profile->preacher->name ?? null,
            topScore: $topScore,
            secondScore: $secondScore,
            margin: $secondScore !== null ? $topScore - $secondScore : null,
            allScores: $allScores,
        );
    }

    /**
     * @param  array<int, float>  $allScores
     */
    public static function noMatch(
        ?float $topScore = null,
        ?float $secondScore = null,
        array $allScores = [],
        ?string $reason = null,
    ): self {
        return new self(
            matched: false,
            errored: false,
            topScore: $topScore,
            secondScore: $secondScore,
            margin: ($topScore !== null && $secondScore !== null) ? $topScore - $secondScore : null,
            allScores: $allScores,
            reason: $reason ?? 'Below threshold',
        );
    }

    public static function noProfiles(): self
    {
        return new self(
            matched: false,
            errored: false,
            reason: 'No active speaker profiles',
        );
    }

    public static function error(string $message): self
    {
        return new self(
            matched: false,
            errored: true,
            reason: $message,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        return [
            'matched' => $this->matched,
            'errored' => $this->errored,
            'matched_profile_id' => $this->matchedProfileId,
            'matched_preacher_id' => $this->matchedPreacherId,
            'matched_preacher_name' => $this->matchedPreacherName,
            'top_score' => $this->topScore,
            'second_score' => $this->secondScore,
            'margin' => $this->margin,
            'reason' => $this->reason,
        ];
    }
}
