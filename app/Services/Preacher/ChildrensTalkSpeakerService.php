<?php

declare(strict_types=1);

namespace App\Services\Preacher;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\ServiceSectionMetadata;
use App\Data\SpeakerMatchResult;
use App\Enums\PreacherSource;
use App\Enums\ServiceSectionType;
use App\Models\Preacher;
use App\Models\ServiceSection;
use App\Models\SpeakerProfile;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Service for automating speaker identification for Children's Talk service sections.
 *
 * This service leverages voice fingerprinting (via SpeakerIdentificationInterface)
 * to predict the speaker of a children's talk. It handles automatic acceptance
 * of high-confidence matches and manages the lifecycle of manual review flags
 * when identification is ambiguous or fails.
 */
class ChildrensTalkSpeakerService
{
    public function __construct(
        private readonly SpeakerIdentificationInterface $speakerService
    ) {}

    /**
     * Predict the speaker for a children's talk section and store results in metadata.
     *
     * Performs speaker identification on the section's extracted audio. If a high-confidence
     * match is found, it is automatically accepted and the section is marked as resolved.
     * Otherwise, the section is flagged for manual administrative review with a
     * descriptive reason (e.g., ambiguous, no match, or short audio).
     *
     * @param  ServiceSection  $section  The children's talk section to analyze
     */
    public function detectAndStore(ServiceSection $section): void
    {
        if ($section->section_type !== ServiceSectionType::ChildrensTalk) {
            return;
        }

        if ($section->reviewedChildrensTalkSpeaker() !== null) {
            return;
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $speakerMetadata = $section->metadata?->childrensTalkSpeaker?->toArray() ?? [];
        $profiles = $this->eligibleProfiles();
        $prediction = $this->predictionPayload($section, $profiles);

        $speakerMetadata['predicted'] = $prediction;

        if ($prediction['outcome'] === 'matched') {
            $speakerMetadata['reviewed'] = [
                'preacher_id' => $prediction['preacher_id'],
                'preacher_name' => $prediction['preacher_name'],
                'source' => (string) $prediction['source'],
                'confidence' => $prediction['confidence'],
                'review_mode' => 'auto_accepted',
                'reviewed_at' => now()->toIso8601String(),
                'reviewed_by_user_id' => null,
            ];

            unset($metadata['review_reason']);
            $metadata['review_flags'] = $this->removeReviewFlag($metadata['review_flags'] ?? [], 'childrens_talk_speaker_review');
            $section->needs_manual_review = false;
        } elseif ($profiles->isEmpty()) {
            unset($speakerMetadata['reviewed']);
            $metadata['review_flags'] = $this->removeReviewFlag($metadata['review_flags'] ?? [], 'childrens_talk_speaker_review');

            if (str_starts_with((string) ($metadata['review_reason'] ?? ''), 'childrens_talk_speaker_')) {
                unset($metadata['review_reason']);
            }

            $section->needs_manual_review = $metadata['review_flags'] !== [];
        } else {
            unset($speakerMetadata['reviewed']);
            $metadata['review_reason'] = $this->reviewReasonForOutcome((string) $prediction['outcome']);
            $metadata['review_flags'] = $this->appendReviewFlag($metadata['review_flags'] ?? [], 'childrens_talk_speaker_review');
            $section->needs_manual_review = true;
        }

        $metadata['childrens_talk_speaker'] = $speakerMetadata;
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
    }

    /**
     * Record a manual speaker identification for a children's talk section.
     *
     * Updates the section metadata with the confirmed speaker (either a canonical
     * Preacher ID or a free-text name) and clears any pending manual review flags.
     *
     * @param  ServiceSection  $section  The section being reviewed
     * @param  int|null  $preacherId  Canonical Preacher ID (preferred)
     * @param  string|null  $speakerName  Free-text speaker name (fallback)
     * @param  int|null  $reviewedByUserId  ID of the admin user performing the review
     */
    public function storeManualReview(
        ServiceSection $section,
        ?int $preacherId,
        ?string $speakerName,
        ?int $reviewedByUserId
    ): void {
        if ($section->section_type !== ServiceSectionType::ChildrensTalk) {
            return;
        }

        $metadata = $section->metadata?->toArray() ?? [];
        $speakerMetadata = $section->metadata?->childrensTalkSpeaker?->toArray() ?? [];
        $normalizedName = is_string($speakerName) ? trim($speakerName) : '';

        $reviewed = null;

        if ($preacherId !== null) {
            $preacher = Preacher::query()->find($preacherId);

            if ($preacher instanceof Preacher) {
                $reviewed = [
                    'preacher_id' => $preacher->id,
                    'preacher_name' => $preacher->name,
                    'source' => PreacherSource::Manual->value,
                    'confidence' => $speakerMetadata['predicted']['confidence'] ?? null,
                    'review_mode' => 'manual_override',
                    'reviewed_at' => now()->toIso8601String(),
                    'reviewed_by_user_id' => $reviewedByUserId,
                ];
            }
        } elseif ($normalizedName !== '') {
            $reviewed = [
                'preacher_id' => null,
                'preacher_name' => $normalizedName,
                'source' => PreacherSource::Manual->value,
                'confidence' => null,
                'review_mode' => 'manual_free_text',
                'reviewed_at' => now()->toIso8601String(),
                'reviewed_by_user_id' => $reviewedByUserId,
            ];
        }

        if ($reviewed === null) {
            return;
        }

        $speakerMetadata['reviewed'] = $reviewed;
        $metadata['childrens_talk_speaker'] = $speakerMetadata;
        unset($metadata['review_reason']);
        $metadata['review_flags'] = $this->removeReviewFlag($metadata['review_flags'] ?? [], 'childrens_talk_speaker_review');

        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->needs_manual_review = false;
    }

    /**
     * Whether the speaker for this section has been resolved.
     *
     * Resolution occurs via either successful auto-identification or manual
     * administrative confirmation.
     *
     * @param  ServiceSection  $section  The section to check
     * @return bool True if a speaker identity is permanently associated
     */
    public function hasResolvedSpeaker(ServiceSection $section): bool
    {
        return $section->hasResolvedChildrensTalkSpeaker();
    }

    /**
     * @param  EloquentCollection<int, SpeakerProfile>  $profiles
     * @return array<string, mixed>
     */
    private function predictionPayload(ServiceSection $section, EloquentCollection $profiles): array
    {
        $audioPath = trim((string) ($section->extracted_audio_path ?? ''));
        if ($audioPath === '') {
            return $this->basePrediction(
                outcome: 'missing_audio',
                reason: 'Extracted section audio is not available yet.'
            );
        }

        if (! (bool) config('media-processing.speaker_identification.enabled', false)) {
            return $this->basePrediction(
                outcome: 'skipped',
                reason: 'Speaker identification is disabled.'
            );
        }

        $minDuration = (int) config('media-processing.speaker_identification.min_duration', 30);
        if ((float) $section->duration < $minDuration) {
            return $this->basePrediction(
                outcome: 'short_audio',
                reason: "Audio is shorter than the {$minDuration}s identification threshold."
            );
        }

        if ($profiles->isEmpty()) {
            return $this->basePrediction(
                outcome: 'no_profiles',
                reason: $this->noProfilesReason()
            );
        }

        $result = $this->speakerService->identify($audioPath, $profiles);

        if ($result->errored) {
            return $this->basePrediction(
                outcome: 'error',
                reason: $result->reason ?? 'Speaker identification failed.'
            );
        }

        if ($result->matched) {
            return array_merge($this->basePrediction(
                outcome: 'matched',
                reason: null
            ), [
                'preacher_id' => $result->matchedPreacherId,
                'preacher_name' => $result->matchedPreacherName,
                'confidence' => $result->topScore,
                'second_confidence' => $result->secondScore,
                'margin' => $result->margin,
                'matched_profile_id' => $result->matchedProfileId,
                'source' => PreacherSource::SpeakerModel->value,
            ]);
        }

        return array_merge($this->basePrediction(
            outcome: $this->predictionOutcomeFromNoMatch($result),
            reason: $result->reason ?? 'No speaker match found.'
        ), [
            'confidence' => $result->topScore,
            'second_confidence' => $result->secondScore,
            'margin' => $result->margin,
            'source' => PreacherSource::SpeakerModel->value,
        ]);
    }

    /**
     * @return EloquentCollection<int, SpeakerProfile>
     */
    private function eligibleProfiles(): EloquentCollection
    {
        return SpeakerProfile::query()
            ->configuredForSpeakerIdentification()
            ->with('preacher')
            ->get();
    }

    private function noProfilesReason(): string
    {
        $provider = (string) config('media-processing.speaker_identification.provider', 'null');
        $modelVersion = (string) config('media-processing.speaker_identification.model_version', '');

        if ($provider === '' || $provider === 'null') {
            return 'No active speaker profiles are available.';
        }

        $reason = "No active speaker profiles are available for provider '{$provider}'";

        if ($modelVersion !== '') {
            $reason .= " and model version '{$modelVersion}'.";
        } else {
            $reason .= '.';
        }

        return $reason;
    }

    private function predictionOutcomeFromNoMatch(SpeakerMatchResult $result): string
    {
        $acceptThreshold = (float) config('media-processing.speaker_identification.accept_threshold', 0.75);
        $marginThreshold = (float) config('media-processing.speaker_identification.margin_threshold', 0.10);

        if (
            $result->topScore !== null
            && $result->topScore >= $acceptThreshold
            && $result->margin !== null
            && $result->margin < $marginThreshold
        ) {
            return 'ambiguous';
        }

        return 'no_match';
    }

    /**
     * @param  array<int, mixed>|mixed  $flags
     * @return array<int, string>
     */
    private function appendReviewFlag(mixed $flags, string $flag): array
    {
        $normalized = collect(is_array($flags) ? $flags : [])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        $normalized[] = $flag;

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<int, mixed>|mixed  $flags
     * @return array<int, string>
     */
    private function removeReviewFlag(mixed $flags, string $flag): array
    {
        return collect(is_array($flags) ? $flags : [])
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '' && $value !== $flag)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function basePrediction(string $outcome, ?string $reason): array
    {
        return [
            'outcome' => $outcome,
            'reason' => $reason,
            'preacher_id' => null,
            'preacher_name' => null,
            'confidence' => null,
            'second_confidence' => null,
            'margin' => null,
            'matched_profile_id' => null,
            'source' => PreacherSource::SpeakerModel->value,
            'decided_at' => now()->toIso8601String(),
        ];
    }

    private function reviewReasonForOutcome(string $outcome): string
    {
        return match ($outcome) {
            'ambiguous' => 'childrens_talk_speaker_ambiguous',
            'no_match' => 'childrens_talk_speaker_no_match',
            'no_profiles' => 'childrens_talk_speaker_unconfigured',
            'short_audio' => 'childrens_talk_speaker_short_audio',
            'missing_audio' => 'childrens_talk_speaker_missing_audio',
            default => 'childrens_talk_speaker_review_required',
        };
    }
}
