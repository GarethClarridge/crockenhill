<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Sermons;

use App\Actions\QueueScriptureEnrichment;
use App\Enums\PreacherSource;
use App\Enums\SermonService;
use App\Enums\SermonVideoVisibilityOverride;
use App\Jobs\AssessSermonVideoQuality;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Services\PreacherResolutionService;
use App\Services\SermonIdentitySyncService;
use App\Services\SermonStorageService;
use App\Services\ThumbnailGenerationService;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;

/**
 * @phpstan-import-type ThumbnailCandidate from \App\Models\Sermon
 */
class EditSermon extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public Sermon $sermon;

    public string $title = '';

    public string $slug = '';

    public string $date = '';

    public string $service = '';

    public string $preacher = '';

    public ?int $preacherId = null;

    public ?string $preacherSource = null;

    public ?float $preacherConfidence = null;

    public ?float $duration = null;

    public ?float $segmentStartTime = null;

    public ?float $segmentEndTime = null;

    public ?string $reference = null;

    public ?string $series = null;

    public ?string $summary = null;

    /** @var array<int, string> */
    public array $points = [];

    public bool $showSummary = true;

    public bool $showPoints = true;

    public bool $isChildrensTalk = false;

    public string $contentTypeLabel = 'Sermon';

    /** @var \Illuminate\Support\Collection<int, string> */
    public \Illuminate\Support\Collection $preacherOptions;

    /** @var array<int, array{id: string, timestamp: float, timestamp_label: string, score: float, overlay_url: ?string, card_url: ?string, preview_url: ?string, is_selected: bool}> */
    public array $thumbnailCandidates = [];

    public ?string $selectedThumbnailCandidateId = null;

    public string $lastGeneratedSlug = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:sermons,slug,'.$this->sermon->id,
            'date' => 'required|date',
            'service' => ['required', Rule::enum(SermonService::class)],
            'preacher' => 'required|string|max:255',
            'preacherId' => 'nullable|integer|exists:preachers,id',
            'preacherSource' => ['nullable', Rule::enum(PreacherSource::class)],
            'preacherConfidence' => 'nullable|numeric|min:0|max:1',
            'duration' => 'nullable|numeric|min:0',
            'segmentStartTime' => 'nullable|numeric|min:0',
            'segmentEndTime' => 'nullable|numeric|min:0|gte:segmentStartTime',
            'reference' => 'nullable|string|max:255',
            'series' => 'nullable|string|max:255',
            'summary' => 'nullable|string|max:1000',
            'points' => 'array',
            'showSummary' => 'boolean',
            'showPoints' => 'boolean',
        ];
    }

    public function mount(Sermon $sermon): void
    {
        $this->authorizeAdmin();

        $sermon->loadMissing('preacherProfile', 'scripturePassage');

        $service = $sermon->service;
        if (! $service instanceof SermonService) {
            throw new \UnexpectedValueException('Sermon service is required.');
        }

        $this->sermon = $sermon;
        $this->isChildrensTalk = $sermon->content_type === \App\Enums\SermonContentType::ChildrensTalk;
        $this->contentTypeLabel = $sermon->content_type->label();
        $this->preacherOptions = Preacher::active()->orderBy('name')->pluck('name', 'id');
        $this->title = $sermon->title;
        $this->slug = $sermon->slug;
        $this->date = $sermon->date->format('Y-m-d');
        $this->service = $service->value;
        $this->preacher = $sermon->displayPreacherName() ?? '';
        $this->preacherId = $sermon->preacher_id;
        $this->preacherSource = $sermon->preacher_source?->value;
        $this->preacherConfidence = $sermon->preacher_confidence;
        $this->duration = $sermon->duration;
        $this->segmentStartTime = $sermon->segment_start_time;
        $this->segmentEndTime = $sermon->segment_end_time;
        $this->reference = $sermon->displayReference();
        $this->series = $sermon->series;
        $this->summary = $sermon->summary;
        $this->points = $sermon->points ?? [];
        $this->showSummary = $sermon->show_summary;
        $this->showPoints = $sermon->show_points;
        $this->lastGeneratedSlug = (string) Str::slug($this->title);
        $this->loadThumbnailCandidates();
    }

    public function updatedTitle(): void
    {
        $generatedSlug = (string) Str::slug($this->title);

        if ($this->slug === '' || $this->slug === $this->lastGeneratedSlug) {
            $this->slug = $generatedSlug;
        }

        $this->lastGeneratedSlug = $generatedSlug;
    }

    public function addPoint(): void
    {
        $this->points[] = '';
    }

    public function removePoint(int $index): void
    {
        unset($this->points[$index]);
        $this->points = array_values($this->points);
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $validated = $this->validate();

        if ($validated['preacherId']) {
            $preacher = Preacher::find($validated['preacherId']);
        } else {
            $preacher = app(PreacherResolutionService::class)->resolve($validated['preacher']);
        }

        if (! ($preacher instanceof Preacher)) {
            $preacher = null;
        }

        $referenceChanged = $this->sermon->reference !== $validated['reference'];
        $newReference = $validated['reference'];
        $scripturePassage = app(SermonIdentitySyncService::class)->findExistingScripturePassage($newReference);
        $scripturePassageId = ($referenceChanged || $this->sermon->scripture_passage_id === null)
            ? $scripturePassage?->id
            : $this->sermon->scripture_passage_id;

        $updateData = [
            'title' => $validated['title'],
            'slug' => $validated['slug'],
            'date' => $validated['date'],
            'service' => $validated['service'],
            'preacher' => $preacher ? $preacher->name : $validated['preacher'],
            'preacher_id' => $preacher?->id,
            'preacher_source' => $preacher ? PreacherSource::MANUAL->value : $validated['preacherSource'],
            'preacher_confidence' => $validated['preacherConfidence'],
            'duration' => $validated['duration'],
            'segment_start_time' => $validated['segmentStartTime'],
            'segment_end_time' => $validated['segmentEndTime'],
            'needs_preacher_review' => false,
            'reference' => $newReference,
            'scripture_passage_id' => $scripturePassageId,
            'series' => $validated['series'],
            'summary' => $validated['summary'],
            'points' => array_filter($this->points),
            'show_summary' => $validated['showSummary'],
            'show_points' => $validated['showPoints'],
        ];

        $this->sermon->update($updateData);

        // Dispatch enrichment after saving if reference was set or changed
        if ($referenceChanged && ! empty($newReference)) {
            app(QueueScriptureEnrichment::class)->dispatch($this->sermon->fresh() ?? $this->sermon);
        }

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

        (new AssessSermonVideoQuality(sermonId: $this->sermon->id))
            ->handle(app(\App\Services\SermonVideoQualityAssessmentService::class));

        $this->sermon->refresh();

        $this->success('Video quality assessment updated');
    }

    public function render(): View
    {
        return view('livewire.admin.sermons.edit-sermon', [
            'services' => SermonService::cases(),
            'preachers' => $this->preacherOptions,
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
