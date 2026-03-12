<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices\Concerns;

use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\ServiceSection;
use App\Services\ChildrensTalkSpeakerService;
use Illuminate\Support\Facades\Storage;

trait ManagesSectionPublication
{
    public function approve(int $sectionId): void
    {
        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        $error = $this->approveSectionForPublication($section);
        if ($error !== null) {
            $this->error($error);

            return;
        }

        $this->success('Section approved and publish job queued.');
    }

    public function reject(int $sectionId): void
    {
        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        if (! $section->transitionTo(ServiceSectionPublicationStatus::REJECTED)) {
            $this->error('This section cannot be rejected in its current state.');

            return;
        }

        $section->save();
        $this->success('Section rejected.');
    }

    public function requeue(int $sectionId): void
    {
        $this->authorizeAdmin();

        $section = ServiceSection::query()->find($sectionId);
        if (! $section instanceof ServiceSection) {
            $this->error('Section not found.');

            return;
        }

        if (! $section->transitionTo(ServiceSectionPublicationStatus::PENDING_APPROVAL)) {
            $this->error('This section cannot be requeued in its current state.');

            return;
        }

        $section->save();
        $this->success('Section moved back to pending approval.');
    }

    private function hasExtractedMedia(ServiceSection $section): bool
    {
        $videoPath = $section->extracted_video_path;
        $audioPath = $section->extracted_audio_path;

        if (! is_string($videoPath) || $videoPath === '' || ! is_string($audioPath) || $audioPath === '') {
            return false;
        }

        $sermonDisk = (string) config('media-processing.storage.sermon_disk', 'public');

        return Storage::disk($sermonDisk)->exists($videoPath)
            && Storage::disk($sermonDisk)->exists($audioPath);
    }

    /**
     * @param  array<string, mixed>|null  $auditMetadata
     */
    protected function approveSectionForPublication(ServiceSection $section, ?array $auditMetadata = null): ?string
    {
        $error = $this->approvalBlocker($section);
        if ($error !== null) {
            return $error;
        }

        if (! $section->transitionTo(ServiceSectionPublicationStatus::APPROVED)) {
            return 'This section cannot be approved in its current state.';
        }

        $metadata = is_array($section->metadata) ? $section->metadata : [];
        $publicationMetadata = is_array($metadata['publication'] ?? null) ? $metadata['publication'] : [];
        $publicationMetadata['approved_signature'] = $section->classificationSignature();
        $publicationMetadata['approved_at'] = now()->toIso8601String();

        if ($auditMetadata !== null) {
            $batchApprovals = is_array($publicationMetadata['batch_approvals'] ?? null)
                ? $publicationMetadata['batch_approvals']
                : [];
            $batchApprovals[] = $auditMetadata;
            $publicationMetadata['batch_approvals'] = $batchApprovals;
        }

        $metadata['publication'] = $publicationMetadata;
        $section->metadata = $metadata;
        $section->save();

        $this->dispatchPublicationJob($section);

        return null;
    }

    protected function approvalBlocker(ServiceSection $section): ?string
    {
        if (! $this->hasExtractedMedia($section)) {
            return 'Section media is missing. Reclassify and prepare candidates again.';
        }

        if (! app(ChildrensTalkSpeakerService::class)->hasResolvedSpeaker($section)) {
            return "Choose a speaker for this children's talk before approving publication.";
        }

        return null;
    }

    protected function dispatchPublicationJob(ServiceSection $section): void
    {
        PublishApprovedServiceSection::dispatch($section->id)
            ->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'));
    }
}
