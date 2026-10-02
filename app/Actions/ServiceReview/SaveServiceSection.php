<?php

declare(strict_types=1);

namespace App\Actions\ServiceReview;

use App\Actions\HoldSectionForContentReview;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Enums\TalkType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\ServiceSection;
use App\Services\ChurchService\ExtractedSectionMediaChecker;
use App\Services\ChurchService\ServiceSectionPublicationTransitionService;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Preacher\TalkSpeakerService;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveServiceSection
{
    public function __construct(
        private readonly TalkSpeakerService $speakerService,
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
     * @param  array<int, array{section_type:string,title:string,end_time?:string|int|float|null,talk_type?:string|null}>  $sectionEdits
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
            'talk_type' => $section->publicationTalkType()->value ?? '',
            'sermon_section_ids' => null,
            'sermon_composition_identity' => null,
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
                'sermon_section_ids' => ['nullable', 'string', 'regex:/^\d+(?:\s*,\s*\d+)*$/'],
                'sermon_composition_identity' => ['nullable', 'string', 'size:64'],
                'end_time' => ['required', 'numeric', 'min:0', 'max:9999999.999'],
                'preacher_id' => ['nullable', 'integer', 'exists:preachers,id'],
                'speaker_name' => ['nullable', 'string', 'max:255'],
                'talk_type' => ['nullable', Rule::in(array_map(
                    static fn (TalkType $type): string => $type->value,
                    TalkType::nonSermon()
                ))],
            ],
            [
                'section_type.required' => 'Choose a section type.',
                'title.required' => 'Enter a title before saving.',
                'end_time.required' => 'Enter an end time.',
                'end_time.numeric' => 'The end time must be a number of seconds.',
                'talk_type.in' => 'Choose a talk type from the list.',
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

            if ($targetType === ServiceSectionType::ShortTalk) {
                $existingSpeaker = $section->publicationTalkSpeaker();
                $speakerName = trim($payload['speaker_name']);
                $preacherId = $payload['preacher_id'];
                $hasSpeakerInput = (is_numeric($preacherId) && (int) $preacherId > 0) || $speakerName !== '';

                if ($existingSpeaker === null && ! $hasSpeakerInput) {
                    $validator->errors()->add('speaker_name', 'Choose a preacher or enter a fallback speaker name for this short talk.');
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

            if ($targetType !== ServiceSectionType::ShortTalk) {
                $validator->errors()->add('end_time', 'Only short-talk candidates can be recut from this review panel.');

                return;
            }

            if ($originalSectionType !== ServiceSectionType::ShortTalk) {
                $validator->errors()->add('end_time', 'The inclusive short-talk candidate must be prepared before it can be recut.');

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
        $compositionChanged = $originalSectionType->value !== $validated['section_type']
            || abs((float) $validated['end_time'] - $originalEndTime) > 0.0005;

        if ($section->section_type === ServiceSectionType::Sermon && is_string($validated['sermon_section_ids'] ?? null)) {
            try {
                $this->withSourceContext($section, fn () => app(SermonExtractionPlanResolver::class)->reviewComposition(
                    $section->processingLog,
                    array_map(static fn (string $id): int => (int) trim($id), explode(',', $validated['sermon_section_ids'])),
                    $validated['sermon_composition_identity'] ?? '',
                    $userId,
                ));
                $section->refresh();
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['sermon_section_ids' => $exception->getMessage()]);
            }
        }

        $targetEndTime = (float) $validated['end_time'];
        $boundaryChanged = $originalSectionType === ServiceSectionType::ShortTalk
            && ServiceSectionType::from($validated['section_type']) === ServiceSectionType::ShortTalk
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

        if ($section->section_type === ServiceSectionType::ShortTalk) {
            $this->speakerService->storeManualReview(
                $section,
                $this->normalizeSpeakerPreacherId($validated['preacher_id']),
                $validated['speaker_name'],
                $userId
            );
        } else {
            unset($metadata['talk_speaker']);
            $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        }

        $section->metadata = ServiceSectionMetadata::fromArray($this->withReviewedTalkType(
            $section->metadata?->toArray() ?? [],
            $section->section_type,
            $validated['talk_type'] ?? null,
            $userId,
        ));

        $compositionOnly = is_string($validated['sermon_section_ids'] ?? null);
        $heldFlags = $section->metadata->reviewFlags;
        $held = $compositionOnly && HoldSectionForContentReview::isHeld($heldFlags);
        $this->confirmSection->apply($section, $userId);
        if ($held) {
            $metadata = $section->metadata?->toArray() ?? [];
            $metadata['review_flags'] = $heldFlags;
            $section->metadata = ServiceSectionMetadata::fromArray($metadata);
            $section->needs_manual_review = true;
        }

        if ($boundaryChanged) {
            $this->invalidateCandidateAfterRecut($section);

            if ($section->publication_status === ServiceSectionPublicationStatus::Approved) {
                if (! $this->publicationTransitions->transition($section, ServiceSectionPublicationStatus::NotApplicable)) {
                    throw new \RuntimeException('Unable to return a recut section to candidate preparation.');
                }
            }

            $section->save();
            $this->recomposeIfChanged($section, $compositionChanged);
            $section->loadMissing('processingLog');
            PrepareSectionPublicationCandidates::dispatchStandalone($section->processingLog);

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
                PrepareSectionPublicationCandidates::dispatchStandalone($section->processingLog);
            }

            $this->recomposeIfChanged($section, $compositionChanged);

            return;
        }

        $section->save();
        $this->recomposeIfChanged($section, $compositionChanged);
    }

    private function recomposeIfChanged(ServiceSection $section, bool $changed): void
    {
        if (! $changed || (! isset($section->processingLog->processing_metadata?->raw['sermon_composition'])
            && ! $section->processingLog->serviceSections()->where('section_type', ServiceSectionType::Sermon)->exists())) {
            return;
        }

        $this->withSourceContext($section, fn () => app(SermonExtractionPlanResolver::class)->compose($section->processingLog->refresh()));
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function withSourceContext(ServiceSection $section, Closure $callback): mixed
    {
        $context = $section->processingLog->historicStagingContext();

        return $context === null ? $callback() : app(HistoricStagingContextRegistry::class)->within($context, $callback);
    }

    /**
     * Record the operator's talk type for a short talk, keeping who chose it and
     * when; a section retyped away from a short talk drops the whole record, as it
     * drops the speaker. Saving the same type again leaves the record untouched.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withReviewedTalkType(array $metadata, ServiceSectionType $sectionType, ?string $talkType, int $userId): array
    {
        if ($sectionType !== ServiceSectionType::ShortTalk) {
            unset($metadata['talk_type']);

            return $metadata;
        }

        if ($talkType === null || $talkType === '') {
            return $metadata;
        }

        $record = is_array($metadata['talk_type'] ?? null) ? $metadata['talk_type'] : ['proposed' => null];

        if (($record['reviewed']['value'] ?? null) !== $talkType) {
            $record['reviewed'] = [
                'value' => $talkType,
                'user_id' => $userId,
                'at' => now()->toIso8601String(),
            ];
        }

        $metadata['talk_type'] = $record;

        return $metadata;
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
        $boundary = is_array($metadata['short_talk_boundary'] ?? null)
            ? $metadata['short_talk_boundary']
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
        $metadata['short_talk_boundary'] = $boundary;

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
