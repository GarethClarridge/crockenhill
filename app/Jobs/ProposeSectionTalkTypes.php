<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\TalkTypeProposer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs only after final structure/bindings, before any output media work. */
class ProposeSectionTalkTypes extends ProcessingJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 1200;

    public function __construct(private MediaProcessingLog $processingLog)
    {
        $this->onQueue((string) config('media-processing.queues.audio', 'audio-processing'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [120, 300, 600];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('propose-section-talk-types-'.$this->processingLog->id))->releaseAfter(30)->expireAfter($this->timeout + 120)];
    }

    public function handle(TalkTypeProposer $proposer): void
    {
        if ($this->refreshAndCheckCancellation($this->processingLog)
            || $this->processingLog->isExcluded() || $this->processingLog->superseded_at !== null) {
            return;
        }
        if (! $this->markProcessingRunAsProcessing($this->processingLog, 'propose_section_talk_types')) {
            return;
        }
        $this->logStepStart('propose_section_talk_types');
        foreach ($this->processingLog->serviceSections()->notSuperseded()->where('section_type', ServiceSectionType::ShortTalk->value)->orderBy('id')->lazyById() as $section) {
            try {
                $result = $proposer->propose($section, settledInChain: true);
                if ($result === 'failed') {
                    Log::warning('Talk-type assessment exhausted its attempts', ['section_id' => $section->id]);
                }
            } catch (Throwable $exception) {
                Log::warning('Talk-type proposal failed for a section', ['section_id' => $section->id, 'exception' => $exception]);
            }
        }
        $this->logStepComplete('propose_section_talk_types');
    }

    protected function onJobFailure(Throwable $exception): void
    {
        $this->initializeStepLogging($this->processingLog->processing_id);
        $this->logStepFailed('propose_section_talk_types', $exception->getMessage());
    }
}
