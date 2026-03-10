<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices\Concerns;

use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\PublishApprovedServiceSection;
use App\Models\ServiceSection;
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

        if (! $this->hasExtractedMedia($section)) {
            $this->error('Section media is missing. Reclassify and prepare candidates again.');

            return;
        }

        if (! $section->transitionTo(ServiceSectionPublicationStatus::APPROVED)) {
            $this->error('This section cannot be approved in its current state.');

            return;
        }

        $metadata = is_array($section->metadata) ? $section->metadata : [];
        $publicationMetadata = is_array($metadata['publication'] ?? null) ? $metadata['publication'] : [];
        $publicationMetadata['approved_signature'] = $section->classificationSignature();
        $publicationMetadata['approved_at'] = now()->toIso8601String();
        $metadata['publication'] = $publicationMetadata;
        $section->metadata = $metadata;
        $section->save();

        PublishApprovedServiceSection::dispatch($section->id)
            ->onQueue((string) config('media-processing.queues.livestream', 'livestream-processing'));

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
}
