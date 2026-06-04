<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sermons;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Sermon;
use App\Services\Media\Thumbnail\ThumbnailGenerationService;
use App\Services\Sermon\SermonStorageService;
use Illuminate\View\View;
use Livewire\Component;

/**
 * @phpstan-import-type ThumbnailCandidate from \App\Models\Sermon
 */
class EditSermonThumbnails extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public Sermon $sermon;

    /** @var array<int, array{id: string, timestamp: float, timestamp_label: string, score: float, overlay_url: ?string, card_url: ?string, preview_url: ?string, is_selected: bool}> */
    public array $thumbnailCandidates = [];

    public ?string $selectedThumbnailCandidateId = null;

    public function mount(Sermon $sermon): void
    {
        $this->sermon = $sermon;
        $this->loadThumbnailCandidates();
    }

    public function placeholder(): View
    {
        return view('livewire.admin.sermons.edit-sermon-thumbnails-placeholder');
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

    public function render(): View
    {
        return view('livewire.admin.sermons.edit-sermon-thumbnails');
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
