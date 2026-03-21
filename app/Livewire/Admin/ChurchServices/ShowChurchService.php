<?php

declare(strict_types=1);

namespace App\Livewire\Admin\ChurchServices;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Services\MediaProcessingIdentityResolver;
use App\Support\ChurchServiceProcessingTimeline;
use App\Support\ServiceRecordTimeline;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;

class ShowChurchService extends Component
{
    use WithAdminAuthorization;
    use WithNotifications;

    public ChurchService $churchService;

    public function mount(ChurchService $churchService): void
    {
        $this->authorizeAdmin();
        $this->abortIfDisabled();

        $this->churchService = $churchService->load([
            'items' => fn ($query) => $query
                ->with('song:id,title')
                ->orderBy('position')
                ->orderBy('id'),
        ]);
    }

    public function render(): View
    {
        $importMetadata = $this->churchService->importMetadataData()->toArray();
        $warnings = is_array($importMetadata['warnings'] ?? null) ? $importMetadata['warnings'] : [];
        $confidenceScore = $importMetadata['confidence_score'] ?? null;
        $processingRuns = $this->relatedProcessingRuns();

        return view('livewire.admin.church-services.show-church-service', [
            'importMetadata' => $importMetadata,
            'warnings' => $warnings,
            'confidenceScore' => is_numeric($confidenceScore) ? (float) $confidenceScore : null,
            'processingRuns' => $processingRuns,
            'processingTimelines' => $this->buildProcessingTimelines($processingRuns),
            'serviceTimelines' => $this->buildServiceTimelines($processingRuns),
        ])->layout('layouts.admin', [
            'title' => 'Service: '.$this->churchService->date->format('j M Y'),
            'heading' => 'Service: '.$this->churchService->date->format('j M Y').' '.$this->churchService->service->label(),
        ]);
    }

    public function reclassify(int $processingLogId): void
    {
        $this->authorizeAdmin();

        $processingLog = MediaProcessingLog::query()->find($processingLogId);
        if (! $processingLog instanceof MediaProcessingLog) {
            $this->error('Processing run not found.');

            return;
        }

        if ($processingLog->processing_type !== MediaType::Livestream) {
            $this->error('Only livestream runs can be reclassified.');

            return;
        }

        if (! $this->processingLogMatchesService($processingLog)) {
            $this->error('Selected run does not belong to this service.');

            return;
        }

        if (! $this->sourceVideoIsAvailable($processingLog)) {
            $this->error('Selected run cannot be reclassified because the original livestream file is no longer available.');

            return;
        }

        // Resolve on demand because Livewire serializes component state between requests.
        app(\App\Services\ProcessingRunOrchestrator::class)->reclassify($processingLog);

        $this->success('Section reclassification queued');
    }

    /**
     * @return EloquentCollection<int, MediaProcessingLog>
     */
    private function relatedProcessingRuns(): EloquentCollection
    {
        $serviceDate = $this->churchService->date->toDateString();
        $serviceType = $this->churchService->service;
        $resolver = $this->identityResolver();

        $query = MediaProcessingLog::query()
            ->livestream()
            ->with([
                'serviceSections' => fn ($query) => $query
                    ->with([
                        'publishedSermon:id,title,slug,content_type',
                        'churchServiceItem' => fn ($q) => $q->withTrashed()->with('song:id,title'),
                    ])
                    ->orderBy('section_order')
                    ->orderBy('id'),
                'processingSteps' => fn ($query) => $query
                    ->whereIn('step', ChurchServiceProcessingTimeline::stepKeys())
                    ->orderBy('started_at')
                    ->orderBy('id'),
            ])
            ->orderByDesc('created_at');

        return $resolver->scopeMatchesIdentity($query, $serviceDate, $serviceType)->get();
    }

    /**
     * @param  EloquentCollection<int, MediaProcessingLog>  $processingRuns
     * @return array<int, list<array<string, mixed>>>
     */
    private function buildServiceTimelines(EloquentCollection $processingRuns): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChurchServiceItem> $items */
        $items = $this->churchService->items;

        return $processingRuns
            ->mapWithKeys(fn (MediaProcessingLog $run): array => [
                $run->id => ServiceRecordTimeline::build($items, $run),
            ])
            ->all();
    }

    /**
     * @param  EloquentCollection<int, MediaProcessingLog>  $processingRuns
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function buildProcessingTimelines(EloquentCollection $processingRuns): array
    {
        return $processingRuns
            ->mapWithKeys(fn (MediaProcessingLog $processingRun): array => [
                $processingRun->id => $this->buildTimelineForRun($processingRun),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTimelineForRun(MediaProcessingLog $processingRun): array
    {
        /** @var Collection<string, SermonProcessingStep> $stepLogs */
        $stepLogs = $processingRun->processingSteps->keyBy('step');
        $latestRecordedIndex = $this->latestRecordedStepIndex($stepLogs);
        $currentTimelineStep = ChurchServiceProcessingTimeline::fromCurrentStep($processingRun->current_step);
        $currentStepIndex = $this->indexForTimelineStep($currentTimelineStep);

        $timeline = [];

        foreach (ChurchServiceProcessingTimeline::steps() as $index => $definition) {
            $recordedStep = $stepLogs->get($definition['key']);
            $timeline[] = $recordedStep instanceof SermonProcessingStep
                ? $this->timelineEntryFromRecordedStep($definition['label'], $recordedStep)
                : $this->timelineEntryFromMissingStep(
                    label: $definition['label'],
                    step: $definition['key'],
                    stepIndex: $index,
                    latestRecordedIndex: $latestRecordedIndex,
                    currentStepIndex: $currentStepIndex,
                    processingRun: $processingRun
                );
        }

        return $timeline;
    }

    /**
     * @param  Collection<string, SermonProcessingStep>  $stepLogs
     */
    private function latestRecordedStepIndex(Collection $stepLogs): ?int
    {
        $indices = $stepLogs
            ->keys()
            ->map(fn (string $step): ?int => $this->indexForTimelineStep($step))
            ->filter(fn (?int $index): bool => $index !== null)
            ->values();

        if ($indices->isEmpty()) {
            return null;
        }

        /** @var int $latest */
        $latest = $indices->max();

        return $latest;
    }

    private function indexForTimelineStep(?string $step): ?int
    {
        if ($step === null) {
            return null;
        }

        foreach (ChurchServiceProcessingTimeline::steps() as $index => $definition) {
            if ($definition['key'] === $step) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineEntryFromRecordedStep(string $label, SermonProcessingStep $step): array
    {
        $status = $step->status->value;
        if ($step->status === ProcessingStatus::STARTED) {
            $status = 'running';
        }

        return [
            'label' => $label,
            'status' => $status,
            'started_at' => $step->started_at,
            'completed_at' => $step->completed_at,
            'duration' => $this->formatDuration($step->started_at?->diffInSeconds($step->completed_at ?? now(), true)),
            'message' => $this->normaliseMessage($step->message),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineEntryFromMissingStep(
        string $label,
        string $step,
        int $stepIndex,
        ?int $latestRecordedIndex,
        ?int $currentStepIndex,
        MediaProcessingLog $processingRun
    ): array {
        $status = 'pending';
        $message = null;

        if ($currentStepIndex !== null && $currentStepIndex === $stepIndex && $processingRun->status->isInProgress()) {
            $status = 'running';
        } elseif ($currentStepIndex !== null && $currentStepIndex === $stepIndex && $processingRun->status->isFailed()) {
            $status = 'failed';
            $message = $this->normaliseMessage($processingRun->error_message);
        } elseif ($currentStepIndex !== null && $currentStepIndex === $stepIndex && $processingRun->status->isCancelled()) {
            $status = 'skipped';
            $message = $this->normaliseMessage($processingRun->error_message) ?? 'Processing cancelled';
        } elseif ($latestRecordedIndex !== null && $latestRecordedIndex > $stepIndex) {
            $status = 'not_recorded';
            $message = 'No step log recorded for this older run.';
        } elseif ($processingRun->status->isComplete()) {
            $status = 'not_recorded';
            $message = 'No step log recorded for this older run.';
        }

        return [
            'label' => $label,
            'status' => $status,
            'started_at' => null,
            'completed_at' => null,
            'duration' => null,
            'message' => $message,
        ];
    }

    private function formatDuration(?float $seconds): ?string
    {
        if (! is_numeric($seconds)) {
            return null;
        }

        $duration = (int) round($seconds);
        if ($duration <= 0) {
            return '0s';
        }

        if ($duration < 60) {
            return $duration.'s';
        }

        $minutes = intdiv($duration, 60);
        $remainingSeconds = $duration % 60;

        if ($minutes < 60) {
            return sprintf('%dm %02ds', $minutes, $remainingSeconds);
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%dh %02dm %02ds', $hours, $remainingMinutes, $remainingSeconds);
    }

    private function normaliseMessage(mixed $message): ?string
    {
        if (! is_string($message)) {
            return null;
        }

        $message = trim($message);

        return $message === '' ? null : $message;
    }

    private function processingLogMatchesService(MediaProcessingLog $processingLog): bool
    {
        $serviceDate = $this->churchService->date->toDateString();
        $serviceType = $this->churchService->service;

        return $this->identityResolver()->matchesService($processingLog, $serviceDate, $serviceType);
    }

    private function identityResolver(): MediaProcessingIdentityResolver
    {
        return app(MediaProcessingIdentityResolver::class);
    }

    private function sourceVideoIsAvailable(MediaProcessingLog $processingLog): bool
    {
        $sourceFilePath = $processingLog->source_file_path;

        if (! is_string($sourceFilePath) || $sourceFilePath === '') {
            return false;
        }

        return Storage::disk((string) config('media-processing.storage.temp_disk', 'local'))
            ->exists($sourceFilePath);
    }

    private function abortIfDisabled(): void
    {
        if (! (bool) config('service-tracking.enabled', true)) {
            abort(404);
        }
    }
}
