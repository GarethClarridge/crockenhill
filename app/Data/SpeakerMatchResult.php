<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\SpeakerProfile;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class SpeakerMatchResult extends Data
{
    /**
     * @param  array<int, float>  $allScores
     * @param  list<array{preacher_id: int|null, preacher_name: string|null, score: float}>  $candidates
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
        public readonly array $candidates = [],
    ) {}

    /**
     * The leading profiles by score, named.
     *
     * `allScores` is keyed by speaker-profile id, which tells a reviewer nothing
     * and does not survive an export. Historic runs fall back to "Visiting
     * Speaker" often enough that whoever reviews the fallback needs to see who
     * the model was choosing between and by how much, so the names travel with
     * the decision.
     *
     * @param  Collection<int, SpeakerProfile>  $profiles
     * @param  array<int, float>  $scores  Profile id to score, highest first
     * @return list<array{preacher_id: int|null, preacher_name: string|null, score: float}>
     */
    public static function namedCandidates(Collection $profiles, array $scores, int $limit = 3): array
    {
        $candidates = [];

        foreach (array_slice($scores, 0, $limit, preserve_keys: true) as $profileId => $score) {
            $profile = $profiles->firstWhere('id', $profileId);

            $candidates[] = [
                'preacher_id' => $profile?->preacher_id,
                'preacher_name' => $profile?->preacher->name ?? null,
                'score' => round($score, 6),
            ];
        }

        return $candidates;
    }

    /**
     * @param  array<int, float>  $allScores
     */
    /**
     * @param  array<int, float>  $allScores
     * @param  list<array{preacher_id: int|null, preacher_name: string|null, score: float}>  $candidates
     */
    public static function matched(
        SpeakerProfile $profile,
        float $topScore,
        ?float $secondScore,
        array $allScores,
        array $candidates = [],
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
            candidates: $candidates,
        );
    }

    /**
     * @param  array<int, float>  $allScores
     */
    /**
     * @param  array<int, float>  $allScores
     * @param  list<array{preacher_id: int|null, preacher_name: string|null, score: float}>  $candidates
     */
    public static function noMatch(
        ?float $topScore = null,
        ?float $secondScore = null,
        array $allScores = [],
        ?string $reason = null,
        array $candidates = [],
    ): self {
        return new self(
            matched: false,
            errored: false,
            topScore: $topScore,
            secondScore: $secondScore,
            margin: ($topScore !== null && $secondScore !== null) ? $topScore - $secondScore : null,
            allScores: $allScores,
            reason: $reason ?? 'Below threshold',
            candidates: $candidates,
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
            'candidates' => $this->candidates,
        ];
    }
}
