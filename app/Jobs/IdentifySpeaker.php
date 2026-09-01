<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\SpeakerIdentificationInterface;
use App\Data\SpeakerMatchResult;
use App\Enums\MediaType;
use App\Enums\PreacherSource;
use App\Models\MediaProcessingLog;
use App\Models\Preacher;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\ErrorException as OpenAIErrorException;
use OpenAI\Exceptions\TransporterException;

class IdentifySpeaker extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Transient infrastructure failures (network, 5xx) will be retried up to 3 times.
     * Deterministic no-match outcomes are caught internally and do not consume retries.
     */
    public int $tries = 3;

    /**
     * Allow up to 5 minutes for embedding extraction.
     */
    public int $timeout = 300;

    public function __construct(
        private MediaProcessingLog $processingLog
    ) {
        $this->onQueue(config('media-processing.speaker_identification.queue', 'speaker-identification'));
    }

    public function handle(SpeakerIdentificationInterface $speakerService): void
    {
        $refreshed = $this->processingLog->fresh();
        if (! $refreshed) {
            Log::warning('IdentifySpeaker: processing log not found', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            return;
        }

        $this->processingLog = $refreshed;
        $this->initializeStepLogging($this->processingLog->processing_id);

        if ($this->isCancelled()) {
            Log::info('IdentifySpeaker job cancelled', [
                'processing_id' => $this->processingLog->processing_id,
            ]);

            return;
        }

        try {
            $this->logStepStart('identifying_speaker', 'Starting speaker identification');

            // Gate 1: Feature flag
            if (! config('media-processing.speaker_identification.enabled', false)) {
                $this->storeDecision('skipped', 'Speaker identification disabled');
                $this->logStepComplete('identifying_speaker', 'Skipped: feature disabled');

                return;
            }

            // Gate 2: Sermon must exist
            if (! $this->processingLog->sermon_id) {
                $this->storeDecision('skipped', 'No sermon record yet');
                $this->logStepComplete('identifying_speaker', 'Skipped: no sermon record');

                return;
            }

            $sermon = Sermon::query()->find($this->processingLog->sermon_id);

            if (! $sermon) {
                $this->storeDecision('skipped', 'Sermon record not found');
                $this->logStepComplete('identifying_speaker', 'Skipped: sermon not found');

                return;
            }

            // Gate 3: Skip if preacher already identified from ID3 tags
            if ($sermon->preacher_source === PreacherSource::Id3) {
                $this->storeDecision('skipped_id3', 'Preacher assigned from ID3 tag');
                $this->logStepComplete('identifying_speaker', 'Skipped: ID3 preacher present');

                return;
            }

            // Gate 4: Audio must be long enough for reliable identification
            $minDuration = (int) config('media-processing.speaker_identification.min_duration', 30);

            if ($sermon->duration !== null && $sermon->duration < $minDuration) {
                $this->storeDecision('skipped_short', "Audio too short ({$sermon->duration}s < {$minDuration}s)");
                $this->logStepComplete('identifying_speaker', 'Skipped: audio too short');

                return;
            }

            // Gate 5: Need at least one active speaker profile to compare against
            $provider = (string) config('media-processing.speaker_identification.provider', 'null');
            $modelVersion = (string) config('media-processing.speaker_identification.model_version', '');

            $profilesQuery = SpeakerProfile::query()->active()->with('preacher');

            if ($provider !== '' && $provider !== 'null') {
                $profilesQuery->where('provider', $provider);

                if ($modelVersion !== '') {
                    $profilesQuery->where('model_version', $modelVersion);
                }
            }

            /** @var Collection<int, SpeakerProfile> $profiles */
            $profiles = $profilesQuery->get();

            if ($profiles->isEmpty()) {
                $reason = 'No active speaker profiles';
                if ($provider !== '' && $provider !== 'null') {
                    $reason = "No active speaker profiles for provider '{$provider}'";
                    if ($modelVersion !== '') {
                        $reason .= " and model version '{$modelVersion}'";
                    }
                }

                $this->storeDecision('skipped_no_profiles', $reason);
                $this->logStepComplete('identifying_speaker', 'Skipped: no active profiles');

                return;
            }

            // Resolve the audio file path
            $audioPath = $this->resolveAudioPath();

            // Run identification. A sermon whose assets have been promoted holds
            // its audio on its own asset disk, not the configured sermon disk, so
            // a replay after promotion must read from there or find nothing.
            $assetDisk = is_string($sermon->asset_disk) && $sermon->asset_disk !== ''
                ? $sermon->asset_disk
                : null;

            $result = $speakerService->identify($audioPath, $profiles, $assetDisk);

            $mode = config('media-processing.speaker_identification.mode', 'shadow');

            if ($result->errored) {
                $this->storeDecision('error', $result->reason ?? 'Speaker identification failed', $result->toLogArray());

                Log::error('IdentifySpeaker: provider error', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sermon_id' => $sermon->id,
                    'reason' => $result->reason,
                ]);
            } elseif ($result->matched && $mode === 'enforce') {
                $this->applyMatch($sermon, $result);
                $this->storeDecision('matched', 'Preacher identified and assigned', $result->toLogArray());

                Log::info('IdentifySpeaker: preacher assigned', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sermon_id' => $sermon->id,
                    'preacher_id' => $result->matchedPreacherId,
                    'preacher_name' => $result->matchedPreacherName,
                    'top_score' => $result->topScore,
                ]);
            } elseif ($result->matched && $mode === 'shadow') {
                // Shadow mode: record confidence but don't change preacher assignment
                $sermon->update(['preacher_confidence' => $result->topScore]);
                $this->storeDecision('shadow_match', 'Match found (shadow mode, not applied)', $result->toLogArray());

                Log::info('IdentifySpeaker: shadow match (not applied)', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sermon_id' => $sermon->id,
                    'would_assign_preacher_id' => $result->matchedPreacherId,
                    'top_score' => $result->topScore,
                ]);
            } else {
                if ($mode === 'enforce') {
                    $this->applyNoMatchFallback($sermon, $result);
                } elseif ($result->topScore !== null) {
                    $sermon->update(['preacher_confidence' => $result->topScore]);
                }

                $this->storeDecision('no_match', $result->reason ?? 'Below threshold', $result->toLogArray());

                Log::info('IdentifySpeaker: no match', [
                    'processing_id' => $this->processingLog->processing_id,
                    'sermon_id' => $sermon->id,
                    'reason' => $result->reason,
                    'top_score' => $result->topScore,
                ]);
            }

            $this->logStepComplete('identifying_speaker', "Speaker identification completed: {$mode}");

        } catch (\Throwable $e) {
            if ($this->isTransientException($e)) {
                // Re-throw so the queue retries the job up to $tries times
                Log::warning('IdentifySpeaker job transient failure — will retry', [
                    'processing_id' => $this->processingLog->processing_id,
                    'error' => $e->getMessage(),
                    'attempts_remaining' => $this->tries - $this->attempts(),
                ]);

                throw $e;
            }

            // Deterministic failure: log and continue so the pipeline chain is not blocked
            Log::error('IdentifySpeaker job failed (non-blocking)', [
                'processing_id' => $this->processingLog->processing_id,
                'error' => $e->getMessage(),
            ]);

            $this->storeDecision('error', $e->getMessage());
            $this->logStepFailed('identifying_speaker', $e->getMessage());
        }
    }

    /**
     * Non-blocking: we do not mark the processing log as failed so the pipeline chain continues.
     */
    protected function onJobFailure(\Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->storeDecision('failed', 'All retries exhausted: '.$exception->getMessage());
        $this->logStepFailed('identifying_speaker', $exception->getMessage());
    }

    /**
     * Transient infrastructure failures should be retried by the queue.
     * These are distinct from deterministic no-match outcomes which must not retry.
     */
    private function isTransientException(\Throwable $e): bool
    {
        if ($e instanceof TransporterException) {
            return true;
        }

        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof OpenAIErrorException && $e->getStatusCode() >= 500) {
            return true;
        }

        return false;
    }

    private function applyMatch(Sermon $sermon, SpeakerMatchResult $result): void
    {
        $sermon->update([
            'preacher_id' => $result->matchedPreacherId,
            'preacher' => $result->matchedPreacherName,
            'preacher_source' => PreacherSource::SpeakerModel->value,
            'preacher_confidence' => $result->topScore,
            'needs_preacher_review' => false,
        ]);
    }

    private function applyNoMatchFallback(Sermon $sermon, SpeakerMatchResult $result): void
    {
        $fallbackPreacher = Preacher::query()
            ->where('slug', 'visiting-speaker')
            ->orWhereRaw('LOWER(name) = ?', ['visiting speaker'])
            ->first();

        if (! $fallbackPreacher) {
            $fallbackPreacher = Preacher::query()->create([
                'name' => 'Visiting Speaker',
                'slug' => 'visiting-speaker',
                'is_active' => true,
            ]);
        }

        $sermon->update([
            'preacher_id' => $fallbackPreacher->id,
            'preacher' => $fallbackPreacher->name,
            'preacher_source' => PreacherSource::Default->value,
            'preacher_confidence' => $result->topScore,
            'needs_preacher_review' => true,
        ]);
    }

    private function resolveAudioPath(): string
    {
        $path = match ($this->processingLog->processing_type) {
            MediaType::Audio => $this->processingLog->source_file_path,
            MediaType::Video, MediaType::Livestream => $this->processingLog->audio_file_path,
        };

        if (empty($path)) {
            throw new \Exception('No audio file path found in processing log');
        }

        return $path;
    }

    /**
     * Store speaker identification decision in processing_metadata.
     *
     * @param  array<string, mixed>  $details
     */
    private function storeDecision(string $outcome, ?string $reason = null, array $details = []): void
    {
        $current = $this->processingLog->processing_metadata?->toArray() ?? [];

        $current['speaker_identification'] = array_merge(
            [
                'outcome' => $outcome,
                'reason' => $reason,
                'decided_at' => now()->toISOString(),
            ],
            $details
        );

        $this->processingLog->update(['processing_metadata' => $current]);
    }
}
