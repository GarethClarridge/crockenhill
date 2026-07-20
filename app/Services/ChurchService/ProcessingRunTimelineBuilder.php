<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ProcessingStatus;
use App\Models\MediaProcessingLog;
use App\Models\SermonProcessingStep;
use App\Support\ChurchServiceProcessingTimeline;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class ProcessingRunTimelineBuilder
{
    /**
     * Build processing step timelines for all runs, keyed by run ID.
     *
     * @param  EloquentCollection<int, MediaProcessingLog>  $processingRuns
     * @return array<int, list<array<string, mixed>>>
     */
    public static function buildAll(EloquentCollection $processingRuns): array
    {
        return $processingRuns
            ->mapWithKeys(fn (MediaProcessingLog $run): array => [
                $run->id => self::buildForRun($run),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function buildForRun(MediaProcessingLog $processingRun): array
    {
        /** @var Collection<string, SermonProcessingStep> $stepLogs */
        $stepLogs = $processingRun->processingSteps->keyBy('step');
        $latestRecordedIndex = self::latestRecordedStepIndex($stepLogs);
        $currentTimelineStep = ChurchServiceProcessingTimeline::fromCurrentStep($processingRun->current_step);
        $currentStepIndex = self::indexForTimelineStep($currentTimelineStep);

        $timeline = [];

        foreach (ChurchServiceProcessingTimeline::steps() as $index => $definition) {
            $recordedStep = $stepLogs->get($definition['key']);
            $timeline[] = $recordedStep instanceof SermonProcessingStep
                ? self::entryFromRecordedStep($definition['label'], $recordedStep)
                : self::entryFromMissingStep(
                    label: $definition['label'],
                    stepIndex: $index,
                    latestRecordedIndex: $latestRecordedIndex,
                    currentStepIndex: $currentStepIndex,
                    processingRun: $processingRun
                );
        }

        return $timeline;
    }

    /**
     * @return array<string, mixed>
     */
    private static function entryFromRecordedStep(string $label, SermonProcessingStep $step): array
    {
        $status = $step->status->value;
        if ($step->status === ProcessingStatus::Started) {
            $status = 'running';
        }

        return [
            'label' => $label,
            'status' => $status,
            'started_at' => $step->started_at,
            'completed_at' => $step->completed_at,
            'duration' => self::formatDuration($step->started_at?->diffInSeconds($step->completed_at ?? now(), true)),
            'message' => self::normaliseMessage($step->message),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function entryFromMissingStep(
        string $label,
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
            $message = self::normaliseMessage($processingRun->error_message);
        } elseif ($currentStepIndex !== null && $currentStepIndex === $stepIndex && $processingRun->status->isCancelled()) {
            $status = 'skipped';
            $message = self::normaliseMessage($processingRun->error_message) ?? 'Processing cancelled';
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

    /**
     * @param  Collection<string, SermonProcessingStep>  $stepLogs
     */
    private static function latestRecordedStepIndex(Collection $stepLogs): ?int
    {
        $indices = $stepLogs
            ->keys()
            ->map(fn (string $step): ?int => self::indexForTimelineStep($step))
            ->filter(fn (?int $index): bool => $index !== null)
            ->values();

        if ($indices->isEmpty()) {
            return null;
        }

        /** @var int $latest */
        $latest = $indices->max();

        return $latest;
    }

    private static function indexForTimelineStep(?string $step): ?int
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

    private static function formatDuration(?float $seconds): ?string
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

    private static function normaliseMessage(mixed $message): ?string
    {
        if (! is_string($message)) {
            return null;
        }

        $message = trim($message);

        return $message === '' ? null : $message;
    }
}
