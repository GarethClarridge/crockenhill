<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sermons;

use App\Actions\SaveSermonDetails;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Enums\SermonVideoVisibilityOverride;
use App\Jobs\AssessSermonVideoQuality;
use App\Livewire\Forms\SermonFormData;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Presenters\SermonViewPresenter;
use App\Services\SermonStorageService;
use App\Services\ThumbnailGenerationService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * @phpstan-import-type ThumbnailCandidate from \App\Models\Sermon
 */
class EditSermon extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public SermonFormData $form;

    public Sermon $sermon;

    public bool $isChildrensTalk = false;

    public string $contentTypeLabel = 'Sermon';

    /** @var Collection<int, string> */
    public Collection $preacherOptions;

    /** @var array<int, array{id: string, timestamp: float, timestamp_label: string, score: float, overlay_url: ?string, card_url: ?string, preview_url: ?string, is_selected: bool}> */
    public array $thumbnailCandidates = [];

    public ?string $selectedThumbnailCandidateId = null;

    public function mount(Sermon $sermon, SermonViewPresenter $sermonViewPresenter): void
    {
        $this->sermon = $sermon;
        $this->form->setSermon($sermon, $sermonViewPresenter);

        $this->isChildrensTalk = $sermon->content_type === SermonContentType::ChildrensTalk;
        $this->contentTypeLabel = $sermon->content_type->label();
        $this->preacherOptions = Preacher::query()->active()->orderBy('name')->pluck('name', 'id');
        $this->loadThumbnailCandidates();
    }

    public function addPoint(): void
    {
        $this->form->addPoint();
    }

    public function removePoint(int $index): void
    {
        $this->form->removePoint($index);
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $validated = $this->form->validate();

        app(SaveSermonDetails::class)->execute($this->sermon, $validated);

        $this->success('Sermon updated');
    }

    public function selectThumbnailCandidate(string $candidateId): void
    {
        $this->authorizeAdmin();
        $this->sermon->refresh();

        $result = app(ThumbnailGenerationService::class)->renderSelectedThumbnailCandidate($this->sermon, $candidateId);

        if (! $result->isSuccess()) {
            $this->error('Thumbnail update failed: '.($result->getErrorMessage() ?? 'Unknown error.'));

            return;
        }

        $this->sermon->update([
            'thumbnail_file_path' => $result->thumbnailPath,
            'thumbnail_metadata' => $result->metadata,
        ]);

        $this->sermon->refresh();
        $this->loadThumbnailCandidates();

        $this->success('Thumbnail updated');
    }

    public function regenerateThumbnails(): void
    {
        $this->authorizeAdmin();
        $this->sermon->refresh();

        if (! $this->sermon->hasVideo()) {
            $this->error('No video file is available for thumbnail generation.');

            return;
        }

        $result = app(ThumbnailGenerationService::class)->regenerateThumbnail($this->sermon);

        if (! $result->isSuccess()) {
            $this->error('Thumbnail regeneration failed: '.($result->getErrorMessage() ?? 'Unknown error.'));

            return;
        }

        $this->sermon->update([
            'thumbnail_file_path' => $result->thumbnailPath,
            'thumbnail_generated_at' => now(),
            'thumbnail_metadata' => $result->metadata,
        ]);

        $this->sermon->refresh();
        $this->loadThumbnailCandidates();

        $this->success('Thumbnails regenerated');
    }

    public function setVideoVisibilityOverride(string $override): void
    {
        $this->authorizeAdmin();

        $visibilityOverride = SermonVideoVisibilityOverride::tryFrom($override);
        if (! $visibilityOverride instanceof SermonVideoVisibilityOverride) {
            $this->error('Invalid video visibility override.');

            return;
        }

        $this->sermon->update([
            'video_visibility_override' => $visibilityOverride,
        ]);

        $this->sermon->refresh();

        $this->success('Video visibility override updated');
    }

    public function rerunVideoQualityAssessment(): void
    {
        $this->authorizeAdmin();
        $this->sermon->refresh();

        if (! $this->sermon->hasVideo()) {
            $this->error('No video file is available for assessment.');

            return;
        }

        dispatch((new AssessSermonVideoQuality(sermonId: $this->sermon->id))
            ->onQueue((string) config('media-processing.queues.video', 'video-processing')));

        $this->success('Video quality assessment queued');
    }

    public function refreshVideoQualityAssessment(): void
    {
        $this->authorizeAdmin();
        $this->sermon->refresh();
    }

    public function render(): View
    {
        return view('livewire.admin.sermons.edit-sermon', [
            'services' => SermonService::cases(),
            'preachers' => $this->preacherOptions,
            'points' => $this->form->points,
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->sermon->title, 'heading' => 'Edit '.$this->contentTypeLabel]);
    }

    private function loadThumbnailCandidates(): void
    {
        $storageService = app(SermonStorageService::class);
        $selectedCandidateId = $this->sermon->thumbnail_metadata?->selectedThumbnailCandidateId;

        $this->selectedThumbnailCandidateId = $selectedCandidateId;
        $this->thumbnailCandidates = array_map(
            fn (array $candidate): array => [
                'id' => $candidate['id'],
                'timestamp' => $candidate['timestamp'],
                'timestamp_label' => $this->formatThumbnailTimestamp($candidate['timestamp']),
                'score' => $candidate['score'],
                'overlay_url' => $storageService->getAdminThumbnailCandidatePreviewUrl($this->sermon, $candidate['id'], 'overlay'),
                'card_url' => $storageService->getAdminThumbnailCandidatePreviewUrl($this->sermon, $candidate['id'], 'card'),
                'preview_url' => $storageService->getAdminThumbnailCandidatePreviewUrl($this->sermon, $candidate['id'], 'overlay')
                    ?? $storageService->getAdminThumbnailCandidatePreviewUrl($this->sermon, $candidate['id'], 'plain'),
                'is_selected' => $selectedCandidateId === $candidate['id'],
            ],
            $this->sermon->thumbnail_candidates,
        );
    }

    private function formatThumbnailTimestamp(float $timestamp): string
    {
        $minutes = (int) floor($timestamp / 60);
        $seconds = (int) round(fmod($timestamp, 60.0));

        return sprintf('%d:%02d', $minutes, $seconds);
    }
}
