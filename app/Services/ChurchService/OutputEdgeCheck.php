<?php

declare(strict_types=1);

namespace App\Services\ChurchService;

use App\Enums\ServiceSectionType;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Models\ServiceSection;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Sermon\SermonExtractionPlanResolver;
use Illuminate\Support\Facades\DB;

/**
 * Where the next cut of a sermon's or a talk's output would put its start and end, and whether
 * each operator edge ({@see OutputEdgeAnswers}) is placed or refused: the plan the cut would
 * follow, resolved read only. Planning recomposes the sermon and writes it on the run, so it runs
 * in a transaction that is rolled back. Nothing is cut, queued or recorded.
 */
class OutputEdgeCheck
{
    public function __construct(
        private readonly SermonExtractionPlanResolver $sermonPlans,
        private readonly CueSafeExtractionPlan $cutPlans,
        private readonly HistoricStagingContextRegistry $contexts,
    ) {}

    /**
     * @return array{status: 'checked'|'decode_pending', reason: string|null, segments: list<array{start_time: float, end_time: float}>, edges: array{start: array{answer: float|null, cut: float|null, planned: float|null, refusal: string|null}, end: array{answer: float|null, cut: float|null, planned: float|null, refusal: string|null}}}
     */
    public function check(ServiceSection $owner): array
    {
        $answers = OutputEdgeAnswers::recordsOf($owner);
        $log = $owner->processingLog;
        $context = $log->historicStagingContext();
        $plan = function () use ($owner, $log): array {
            if ($owner->section_type === ServiceSectionType::ShortTalk) {
                $planned = $this->cutPlans->forSection($owner);

                return ['segments' => $planned['segments'], 'audit' => $planned['cue_edge_widening'], 'reason' => null];
            }

            $planned = $this->sermonPlans->resolve($log);
            $reason = $planned['metadata']['reason'] ?? null;

            if ($reason === 'edge_word_timings_missing') {
                throw new OutputEdgeTimingsMissing((string) ($planned['metadata']['edge_word_timings_error'] ?? 'Edge word timings are not decoded.'));
            }

            return ['segments' => $planned['segments'], 'audit' => $planned['metadata']['cue_edge_widening'] ?? [], 'reason' => is_string($reason) ? $reason : null];
        };

        DB::beginTransaction();

        try {
            $result = $context === null ? $plan() : $this->contexts->within($context, $plan);
        } catch (OutputEdgeTimingsMissing) {
            $result = null;
        } finally {
            DB::rollBack();
        }

        $edges = [];
        /** @var list<array<string, mixed>> $audit */
        $audit = $result['audit'] ?? [];

        foreach (['start', 'end'] as $edge) {
            $entries = collect($audit)->filter(static fn (array $entry): bool => $entry['edge'] === $edge && isset($entry['operator_edge']));
            $placed = $entries->firstWhere('reason', CueSafeExtractionPlan::OPERATOR_SET_EDGE);
            $refused = $entries->firstWhere('reason', CueSafeExtractionPlan::OPERATOR_EDGE_REFUSED);
            $segments = $result['segments'] ?? [];
            $cut = $segments === [] ? null : (float) ($edge === 'start' ? $segments[0]['start_time'] : $segments[array_key_last($segments)]['end_time']);
            $edges[$edge] = [
                'answer' => $answers[$edge]['time'] ?? null,
                'cut' => $cut,
                'planned' => is_array($placed) ? (float) $placed['planned_time'] : $cut,
                'refusal' => is_array($refused) ? (string) $refused['refusal'] : null,
            ];
        }

        return [
            'status' => $result === null ? 'decode_pending' : 'checked',
            'reason' => $result['reason'] ?? null,
            'segments' => $result['segments'] ?? [],
            'edges' => $edges,
        ];
    }
}
