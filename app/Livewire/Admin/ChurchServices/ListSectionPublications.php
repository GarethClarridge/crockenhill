<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\ServiceSectionPublicationStatus;
use App\Jobs\PublishApprovedServiceSection;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ServiceSection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ListSectionPublications extends Component
{
    use WithAdminAuthorization;
    use WithNotifications;
    use WithPagination;

    public string $search = '';

    public string $publicationStatus = ServiceSectionPublicationStatus::PENDING_APPROVAL->value;

    /** @var array<int, string> */
    protected array $queryString = ['search', 'publicationStatus'];

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->abortIfDisabled();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPublicationStatus(): void
    {
        $this->resetPage();
    }

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

    public function render(): View
    {
        $search = trim($this->search);
        $escapedSearch = $this->escapeLike($search);
        $searchPattern = '%'.$escapedSearch.'%';

        $sections = ServiceSection::query()
            ->with([
                'processingLog:id,processing_id,extracted_date,extracted_service,processing_metadata',
                'publishedSermon:id,title,slug',
            ])
            ->when($this->publicationStatus !== '', fn ($query) => $query->where('publication_status', $this->publicationStatus))
            ->when($search !== '', function ($query) use ($searchPattern): void {
                $query->where(function ($query) use ($searchPattern): void {
                    $query->where('title', 'like', $searchPattern)
                        ->orWhere('section_type', 'like', $searchPattern)
                        ->orWhereHas('processingLog', fn ($query) => $query->where('processing_id', 'like', $searchPattern));
                });
            })
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('livewire.admin.church-services.list-section-publications', [
            'sections' => $sections,
            'statuses' => ServiceSectionPublicationStatus::cases(),
        ])->layout('layouts.admin', [
            'title' => 'Section Publications',
            'heading' => 'Section Publications',
        ]);
    }

    private function abortIfDisabled(): void
    {
        if (
            ! (bool) config('service-tracking.enabled', true)
            || ! (bool) config('media-processing.section_publishing.enabled', true)
        ) {
            abort(404);
        }
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

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\%_');
    }
}
