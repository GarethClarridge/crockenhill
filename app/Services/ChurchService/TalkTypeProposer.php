<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Actions\ProposeTalkType;
use App\Contracts\TalkTypeClassifier;
use App\Data\TalkTypeInput;
use App\Data\TalkTypeProposal;
use App\Enums\ProcessingStatus;
use App\Enums\TalkType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Processing\ProcessingPhaseRegistry;
use Illuminate\Support\Sleep;
use Throwable;

/** Shared proposal-only route for settled weekly and existing sections. */
class TalkTypeProposer
{
    public const VERSION = 'talk-type-proposer-1';

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly TalkTypeInputBuilder $inputs,
        private readonly TalkTypeRules $rules,
        private readonly TalkTypeClassifier $classifier,
        private readonly ProposeTalkType $persistence,
        private readonly ProcessingPhaseRegistry $phases,
    ) {}

    public function input(ServiceSection $section, ?string $refreshReason = null): TalkTypeInput
    {
        return $this->inputs->build($section, [
            'classifier' => self::VERSION,
            'rules' => TalkTypeRules::VERSION,
            'prompt' => OpenAiTalkTypeClassifier::PROMPT_VERSION,
            'schema' => OpenAiTalkTypeClassifier::SCHEMA_VERSION,
            'model' => config('media-processing.talk_type.model'),
            'reasoning_effort' => config('media-processing.talk_type.reasoning_effort'),
            'max_completion_tokens' => config('media-processing.talk_type.max_completion_tokens'),
            'requested_service_tier' => config('openai.service_tier'),
            'refresh_reason' => $refreshReason,
        ]);
    }

    /**
     * Chain placement establishes the settled phase; standalone calls require a terminal run
     * or a recognised downstream failure. A held section need not have extracted media.
     *
     * @return list<string>
     */
    public function ineligibleReasons(TalkTypeInput $input, bool $settledInChain = false): array
    {
        $reasons = $input->ineligibleReasons;
        $run = MediaProcessingLog::query()->whereKey($input->provenance['run_id'] ?? null)->first();
        if ($run === null) {
            return [...$reasons, 'missing_run'];
        }
        if ($run->isExcluded() || $run->superseded_at !== null) {
            $reasons[] = 'superseded_or_excluded_run';
        }
        if ($run->status === ProcessingStatus::Failed && $run->current_step === 'manual_review_required'
            && ($run->manualReviewMetadata()['reason_code'] ?? null) === 'llm_structure_validation_failed') {
            $reasons[] = 'unsettled_structure_validation';
        }
        if ($settledInChain) {
            if ($run->status !== ProcessingStatus::Processing || $run->current_step !== 'propose_section_talk_types') {
                $reasons[] = 'outside_proposal_phase';
            }
        } elseif (! $this->settledStandaloneRun($run)) {
            $reasons[] = 'unstable_run';
        }
        $previousEnd = null;
        $previousOrder = null;
        foreach ($input->evidence['outline'] ?? [] as $entry) {
            if (($previousEnd !== null && $entry['start'] < $previousEnd - 0.001)
                || ($previousOrder !== null && $entry['section_order'] <= $previousOrder)) {
                $reasons[] = 'unstable_outline';
            }
            $previousEnd = $entry['end'];
            $previousOrder = $entry['section_order'];
        }

        return array_values(array_unique($reasons));
    }

    /** @return 'stored'|'unchanged'|'stale'|'ineligible'|'failed' */
    public function propose(ServiceSection $section, bool $settledInChain = false, ?string $refreshReason = null): string
    {
        $input = $this->input($section, $refreshReason);
        if ($this->ineligibleReasons($input, $settledInChain) !== []) {
            return 'ineligible';
        }
        $current = $section->fresh()?->metadata?->talkType->raw ?? [];
        if (($current['input_hash'] ?? null) === $input->inputHash()
            && in_array($current['proposal_status'] ?? null, ['completed', 'unresolved'], true)) {
            return 'unchanged';
        }
        $proposal = $this->rules->evaluate($input);
        $failed = false;
        if (($proposal->signals['needs_model'] ?? false) === true) {
            [$proposal, $failed] = $this->modelProposal($input, $proposal);
        }
        if ($proposal->proposed === TalkType::ChildrensTalk && ($input->evidence['service'] ?? null) !== 'morning') {
            $proposal = new TalkTypeProposal(
                proposedSeries: $proposal->proposedSeries,
                disposition: 'uncertain',
                reasons: [['reason' => 'A family talk requires an established morning service.'], ...$proposal->reasons],
                decidedBy: $proposal->decidedBy,
                ruleId: 'T9.service_veto',
                status: 'unresolved',
                provenance: $proposal->provenance,
                signals: [...$proposal->signals, 'service_veto' => true],
            );
        }

        return $section->getConnection()->transaction(function () use ($input, $proposal, $settledInChain, $failed): string {
            MediaProcessingLog::query()->whereKey($input->provenance['run_id'])->lockForUpdate()->firstOrFail();
            if ($this->ineligibleReasons($input, $settledInChain) !== []) {
                return 'ineligible';
            }
            $outcome = $this->persistence->persist($input, $proposal);

            return $outcome === 'stored' && $failed ? 'failed' : $outcome;
        });
    }

    private function settledStandaloneRun(MediaProcessingLog $run): bool
    {
        if ($run->status === ProcessingStatus::Completed) {
            return true;
        }
        if ($run->status !== ProcessingStatus::Failed) {
            return false;
        }
        $phases = collect($this->phases->phasesForPipeline($run->processingPipelineProfile()));
        $proposal = $phases->firstWhere('step', 'propose_section_talk_types');
        $current = $phases->firstWhere('step', $run->current_step);

        return isset($proposal['job_offset'], $current['job_offset']) && $current['job_offset'] >= $proposal['job_offset'];
    }

    /** @return array{TalkTypeProposal, bool} */
    private function modelProposal(TalkTypeInput $input, TalkTypeProposal $rules): array
    {
        $last = new TalkTypeProposal(status: 'failed', reasons: [['reason' => 'Talk-type assessment failed.']]);
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $last = $this->classifier->classify($input, $rules);
                if ($last->status !== 'failed') {
                    return [new TalkTypeProposal(
                        proposed: $last->proposed, proposedSeries: $last->proposedSeries,
                        disposition: $last->disposition, confidence: $last->confidence,
                        reasons: $last->reasons, decidedBy: $last->decidedBy, ruleId: $last->ruleId,
                        status: $last->status, provenance: [...$last->provenance, 'attempts' => $attempt],
                        signals: [...$rules->signals, ...$last->signals, 'needs_model' => false],
                    ), false];
                }
            } catch (Throwable $exception) {
                $last = new TalkTypeProposal(status: 'failed', decidedBy: 'model',
                    reasons: [['reason' => $exception->getMessage()]],
                    provenance: ['exception_class' => $exception::class, 'previous_exception_class' => $exception->getPrevious() === null ? null : $exception->getPrevious()::class]);
            }
            if ($attempt < self::MAX_ATTEMPTS) {
                Sleep::for($attempt === 1 ? 1 : 5)->seconds();
            }
        }

        return [new TalkTypeProposal(
            proposedSeries: $last->proposedSeries ?? $rules->proposedSeries,
            disposition: 'uncertain',
            reasons: $last->reasons,
            decidedBy: 'model', status: 'unresolved',
            provenance: [...$last->provenance, 'attempts' => self::MAX_ATTEMPTS],
            signals: [...$rules->signals, ...$last->signals, 'needs_model' => false, 'retry_exhausted' => true],
        ), true];
    }
}
