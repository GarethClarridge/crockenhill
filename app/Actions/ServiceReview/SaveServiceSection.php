<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\Preacher\ChildrensTalkSpeakerService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveServiceSection
{
    public function __construct(
        private readonly ChildrensTalkSpeakerService $speakerService,
        private readonly ConfirmServiceSection $confirmSection,
        private readonly ServiceSectionPublicationTransitionService $publicationTransitions,
        private readonly ExtractedSectionMediaChecker $mediaChecker,
    ) {}

    /**
     * Validate and persist section type, title, and speaker corrections from the review dashboard.
     *
     * Throws ValidationException on invalid input; throws \RuntimeException if the section
     * is no longer a review candidate.
     *
     * @param  array<int, array{section_type:string,title:string,end_time?:string|int|float|null}>  $sectionEdits
     * @param  array<int, array{preacher_id:string,speaker_name:string}>  $speakerEdits
     *
     * @throws ValidationException
     */
    public function execute(
        ServiceSection $section,
        array $sectionEdits,
        array $speakerEdits,
        int $userId,
    ): void {
        $section->loadMissing('churchServiceItem');

        $payload = array_merge([
            'section_type' => $section->section_type->value,
            'title' => (string) ($section->title ?? ''),
            'end_time' => (string) $section->end_time,
            'preacher_id' => '',
            'speaker_name' => '',
        ], $sectionEdits[$section->id] ?? [], $speakerEdits[$section->id] ?? []);

        $originalSectionType = $section->section_type;
        $originalEndTime = (float) $section->end_time;
        $originalStartTime = (float) $section->start_time;

        $validator = Validator::make(
            $payload,
            [
                'section_type' => ['required', Rule::in(array_map(
                    static fn (ServiceSectionType $type): string => $type->value,
                    ServiceSectionType::cases()
                ))],
                'title' => ['required', 'string', 'max:255'],
                'end_time' => ['required', 'numeric', 'min:0', 'max:9999999.999'],
                'preacher_id' => ['nullable', 'integer', 'exists:preachers,id'],
                'speaker_name' => ['nullable', 'string', 'max:255'],
            ],
            [
                'section_type.required' => 'Choose a section type.',
                'title.required' => 'Enter a title before saving.',
                'end_time.required' => 'Enter an end time.',
                'end_time.numeric' => 'The end time must be a number of seconds.',
            ]
        );

        $validator->after(function (\Illuminate\Validation\Validator $validator) use (
            $payload,
            $section,
            $originalSectionType,
            $originalEndTime,
            $originalStartTime,
        ): void {
            $targetType = ServiceSectionType::tryFrom($payload['section_type']);

            if ($targetType === ServiceSectionType::ChildrensTalk) {
                $existingSpeaker = $section->publicationChildrensTalkSpeaker();
                $speakerName = trim($payload['speaker_name']);
                $preacherId = $payload['preacher_id'];
                $hasSpeakerInput = (is_numeric($preacherId) && (int) $preacherId > 0) || $speakerName !== '';

                if ($existingSpeaker === null && ! $hasSpeakerInput) {
                    $validator->errors()->add('speaker_name', "Choose a preacher or enter a fallback speaker name for this children's talk.");
                }
            }

            $endTime = $payload['end_time'] ?? null;

            if (! is_numeric($endTime)) {
                return;
            }

            $endTime = (float) $endTime;
            $endTimeChanged = abs($endTime - $originalEndTime) > 0.0005;

            if ($endTime <= $originalStartTime) {
                $validator->errors()->add('end_time', 'The end time must be after the start time.');
            }

            if (! $endTimeChanged) {
                return;
            }

            if ($targetType !== ServiceSectionType::ChildrensTalk) {
                $validator->errors()->add('end_time', "Only children's-talk candidates can be recut from this review panel.");

                return;
            }

            if ($originalSectionType !== ServiceSectionType::ChildrensTalk) {
                $validator->errors()->add('end_time', "The inclusive children's-talk candidate must be prepared before it can be recut.");

                return;
            }

            if ($endTime > $originalEndTime) {
                $validator->errors()->add('end_time', 'A reviewed recut may only shorten the current inclusive candidate.');
            }

            if ($section->publication_status === ServiceSectionPublicationStatus::Published) {
                $validator->errors()->add('end_time', 'Published sections cannot be recut here.');
            }
        });

        $validated = $validator->validate();

        $targetEndTime = (float) $validated['end_time'];
        $boundaryChanged = $originalSectionType === ServiceSectionType::ChildrensTalk
            && ServiceSectionType::from($validated['section_type']) === ServiceSectionType::ChildrensTalk
            && abs($targetEndTime - $originalEndTime) > 0.0005;

        $section->section_type = ServiceSectionType::from($validated['section_type']);
        $section->title = trim($validated['title']);

        $metadata = $section->metadata?->toArray() ?? [];

        if ($boundaryChanged) {
            $metadata = $this->recordReviewedRecut(
                metadata: $metadata,
                originalStartTime: $originalStartTime,
                originalEndTime: $originalEndTime,
                newEndTime: $targetEndTime,
                userId: $userId,
            );
            $section->end_time = $targetEndTime;
            $section->duration = max(0.0, $targetEndTime - $originalStartTime);
            $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        }

        if ($section->section_type === ServiceSectionType::ChildrensTalk) {
            $this->speakerService->storeManualReview(
                $section,
                $this->normalizeSpeakerPreacherId($validated['preacher_id']),
                $validated['speaker_name'],
                $userId
            );
        } else {
            unset($metadata['childrens_talk_speaker']);
            $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        }

        $this->confirmSection->apply($section, $userId);

        if ($boundaryChanged) {
            $this->invalidateCandidateAfterRecut($section);

            if ($section->publication_status === ServiceSectionPublicationStatus::Approved) {
                if (! $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::NotApplicable)) {
                    throw new \RuntimeException('Unable to return a recut section to candidate preparation.');
                }
            }

            $section->save();
            $section->loadMissing('processingLog');
            PrepareSectionPublicationCandidates::dispatch($section->processingLog)
                ->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'));

            return;
        }

        if (
            ! $this->publicationTransitions->isPublishableType($section)
            && $section->publication_status !== ServiceSectionPublicationStatus::NotApplicable
        ) {
            $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::NotApplicable);
        } elseif (
            $this->publicationTransitions->isPublishableType($section)
            && ! $section->needs_manual_review
            && $section->publication_status === ServiceSectionPublicationStatus::NotApplicable
        ) {
            $section->save();

            if ($this->mediaChecker->hasExtractedMedia($section)) {
                if ($section->extracted_at === null) {
                    $section->extracted_at = now();
                }

                if ($section->unpublished_expires_at === null) {
                    $retainHours = (int) config('media-processing.section_publishing.retain_unpublished_hours', 48);
                    $section->unpublished_expires_at = now()->addHours(max(1, $retainHours));
                }

                $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::PendingApproval);
                $section->save();
            } else {
                $section->loadMissing('processingLog');
                PrepareSectionPublicationCandidates::dispatch($section->processingLog)
                    ->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'));
            }

            return;
        }

        $section->save();
    }

    private function normalizeSpeakerPreacherId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function recordReviewedRecut(
        array $metadata,
        float $originalStartTime,
        float $originalEndTime,
        float $newEndTime,
        int $userId,
    ): array {
        $boundary = is_array($metadata['childrens_talk_boundary'] ?? null)
            ? $metadata['childrens_talk_boundary']
            : [];
        $reviewedRecuts = is_array($boundary['reviewed_recuts'] ?? null)
            ? $boundary['reviewed_recuts']
            : [];

        $reviewedRecuts[] = [
            'from' => [
                'start_time' => $originalStartTime,
                'end_time' => $originalEndTime,
            ],
            'to' => [
                'start_time' => $originalStartTime,
                'end_time' => $newEndTime,
            ],
            'decided_at' => now()->toIso8601String(),
            'decided_by_user_id' => $userId,
        ];
        $boundary['reviewed_recuts'] = array_values($reviewedRecuts);
        $metadata['childrens_talk_boundary'] = $boundary;

        return $metadata;
    }

    private function invalidateCandidateAfterRecut(ServiceSection $section): void
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $publication = $metadata['publication'] ?? null;

        if (is_array($publication)) {
            unset($publication['approved_signature'], $publication['approved_at']);

            if ($publication === []) {
                unset($metadata['publication']);
            } else {
                $metadata['publication'] = $publication;
            }
        }

        $section->extracted_video_path = null;
        $section->extracted_audio_path = null;
        $section->extracted_at = null;
        $section->unpublished_expires_at = null;
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
    }
}
