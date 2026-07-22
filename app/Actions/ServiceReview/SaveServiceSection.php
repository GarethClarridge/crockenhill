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
     * @param  array<int, array{section_type:string,title:string}>  $sectionEdits
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
            'preacher_id' => '',
            'speaker_name' => '',
        ], $sectionEdits[$section->id] ?? [], $speakerEdits[$section->id] ?? []);

        $validator = Validator::make(
            $payload,
            [
                'section_type' => ['required', Rule::in(array_map(
                    static fn (ServiceSectionType $type): string => $type->value,
                    ServiceSectionType::cases()
                ))],
                'title' => ['required', 'string', 'max:255'],
                'preacher_id' => ['nullable', 'integer', 'exists:preachers,id'],
                'speaker_name' => ['nullable', 'string', 'max:255'],
            ],
            [
                'section_type.required' => 'Choose a section type.',
                'title.required' => 'Enter a title before saving.',
            ]
        );

        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($payload, $section): void {
            $targetType = ServiceSectionType::tryFrom($payload['section_type']);

            if ($targetType !== ServiceSectionType::ChildrensTalk) {
                return;
            }

            $existingSpeaker = $section->publicationChildrensTalkSpeaker();
            $speakerName = trim($payload['speaker_name']);
            $preacherId = $payload['preacher_id'];
            $hasSpeakerInput = (is_numeric($preacherId) && (int) $preacherId > 0) || $speakerName !== '';

            if ($existingSpeaker === null && ! $hasSpeakerInput) {
                $validator->errors()->add('speaker_name', "Choose a preacher or enter a fallback speaker name for this children's talk.");
            }
        });

        $validated = $validator->validate();

        $section->section_type = ServiceSectionType::from($validated['section_type']);
        $section->title = trim($validated['title']);

        $metadata = $section->metadata?->toArray() ?? [];

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
}
