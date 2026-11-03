<?php

declare(strict_types=1);

namespace App\Services\Sermon;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PrepareOutputEdgeWordTimings;
use App\Services\ChurchService\SermonSongIntroductionCheck;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The sermon cut a proposed structure would produce, read without writing anything.
 *
 * The proposal is synced onto a copy of the run inside a transaction that is always rolled
 * back, and the production {@see SermonExtractionPlanResolver} plans the cut from it. The copy
 * has no sections and no media of its own, so the sync cannot delete an extracted file.
 */
class SermonCutProbe
{
    /**
     * The most times {@see asWrittenWithEvidence()} gathers evidence and plans again. Each round
     * decodes every edge of the planned spans and answers the sermon-end question, so a second
     * round is needed only for an edge the first plan's widening reached.
     */
    private const EVIDENCE_ROUNDS = 4;

    /** Flags the ensemble adds for its own questions; stripped to read the cut as written. */
    private const ENSEMBLE_FLAGS = [
        ServiceStructureValidator::FLAG_ENSEMBLE_DISAGREES,
        ServiceStructureValidator::FLAG_ENSEMBLE_DEGRADED,
    ];

    public function __construct(
        private readonly ServiceSectionSyncService $sync,
        private readonly SermonExtractionPlanResolver $resolver,
        private readonly SermonSongIntroductionCheck $introduction,
        private readonly PrepareOutputEdgeWordTimings $wordTimings,
        private readonly OutputEdgeWordTimings $edgeWords,
    ) {}

    /**
     * The cut as the pipeline would plan it now, and the cut it would plan if every
     * ensemble question were answered by accepting what was written.
     *
     * @return array{gated: array<string, mixed>, as_written: array<string, mixed>}
     */
    public function probe(MediaProcessingLog $log, ServiceStructure $structure, ChurchServiceTranscript $transcript): array
    {
        return [
            'gated' => $this->plan($log, $structure, $transcript),
            'as_written' => $this->asWritten($log, $structure, $transcript),
        ];
    }

    /**
     * The cut the pipeline would plan if every ensemble question were answered by accepting
     * what was written.
     *
     * @return array<string, mixed>
     */
    public function asWritten(MediaProcessingLog $log, ServiceStructure $structure, ChurchServiceTranscript $transcript): array
    {
        $accepted = ServiceStructure::fromSections(
            array_map(
                static fn (ServiceStructureSection $section): ServiceStructureSection => $section->withoutReviewFlags()
                    ->withReviewFlags(array_values(array_diff($section->reviewFlags, self::ENSEMBLE_FLAGS))),
                $structure->sections,
            ),
            $structure->notes,
            $structure->model,
            $structure->summary,
            $structure->notices,
            $structure->chapterMarkers,
            $structure->sermonAbsence,
        );

        return $this->plan($log, $accepted, $transcript);
    }

    /**
     * {@see asWritten()}, after decoding the edge words and answering the sermon-end question
     * the cut waits on. Evidence is banked on the run itself, outside the rolled-back probe, so
     * the next plan and every later replay read it. A plan that still waits when nothing new can
     * be gathered, or when gathering fails, is returned as it is.
     *
     * @return array<string, mixed>
     */
    public function asWrittenWithEvidence(MediaProcessingLog $log, ServiceStructure $structure, ChurchServiceTranscript $transcript): array
    {
        $gathered = [];

        for ($round = 0; ; $round++) {
            $plan = $this->asWritten($log, $structure, $transcript);

            if (($plan['strategy'] ?? null) !== 'edge_word_timings_missing' || $round === self::EVIDENCE_ROUNDS) {
                return $plan;
            }

            /** @var list<array{start_time: float, end_time: float}> $spans */
            $spans = $plan['structure_segments'] ?? [];
            $windows = $this->wordTimings->windows($log, $spans, $transcript);
            /** @var array{start: float, end: float, window_rule?: string}|null $window */
            $window = $plan['edge_word_timings_window'] ?? null;

            if (is_array($window)) {
                $windows[$this->edgeWords->kind($this->edgeWords->identity($log, $window))] = $window;
            }

            /** @var list<array{identity: array<string, mixed>, prompt: string, lines: list<array{n: int, start: float, end: float, text: string, music: 'no'|'may'|'yes'}>, end?: float|null}> $questions */
            $questions = array_values(array_filter([
                $plan['pending_evidence']['sermon_song_introduction'] ?? null,
                ...array_values($plan['pending_evidence']['part_song_introductions'] ?? []),
            ], 'is_array'));
            $keys = [...array_keys($windows), ...array_map(static fn (array $question): string => hash('sha256', json_encode($question['identity'], JSON_THROW_ON_ERROR)), $questions)];

            if (array_diff($keys, $gathered) === []) {
                return $plan;
            }

            $gathered = array_values(array_unique([...$gathered, ...$keys]));

            try {
                $this->wordTimings->prepareWindows($log, $windows);

                foreach ($questions as $question) {
                    $this->introduction->answerQuestion($log, $question);
                }
            } catch (Throwable $exception) {
                Log::warning('Cut probe could not gather the evidence its plan waits on', [
                    'processing_id' => $log->processing_id,
                    'error' => $exception::class.': '.$exception->getMessage(),
                ]);

                return $plan;
            }

            $log = $log->fresh() ?? $log;
        }
    }

    /** @return array<string, mixed> */
    private function plan(MediaProcessingLog $log, ServiceStructure $structure, ChurchServiceTranscript $transcript): array
    {
        DB::beginTransaction();

        try {
            $copy = $log->replicate(['dedup_key']);
            $copy->processing_id = Str::uuid()->toString();
            $copy->saveQuietly();

            $this->sync->sync($copy, $structure->toClassifiedSections($copy, $transcript));
            $candidate = $copy->fresh() ?? $copy;
            // Sections belong to the temporary row; evidence belongs to the source recording.
            // The explicit transcript keeps composition read-only, so this ID is never saved.
            $candidate->processing_id = $log->processing_id;
            $plan = $this->resolver->resolve($candidate, transcript: $transcript);
            $evidenceRequest = [];
            if (($plan['metadata']['reason'] ?? null) === 'edge_word_timings_missing') {
                $spans = $plan['metadata']['structure_segments'] ?? [];
                $question = $this->introduction->question($candidate, $spans);
                if ($question !== null && $question['lines'] !== [] && $this->introduction->read($candidate, $spans) === null) {
                    $evidenceRequest['sermon_song_introduction'] = $question;
                }
                foreach ($this->introduction->innerParts($candidate, $spans) as $part) {
                    $partQuestion = $this->introduction->question($candidate, $spans, $part);
                    if ($partQuestion !== null && $partQuestion['lines'] !== [] && $this->introduction->read($candidate, $spans, $part) === null) {
                        $evidenceRequest['part_song_introductions'][$part] = $partQuestion;
                    }
                }
            }

            return [
                'mode' => $plan['mode'],
                'strategy' => $plan['metadata']['strategy'] ?? $plan['metadata']['reason'] ?? null,
                'segments' => $plan['segments'],
                'from_sections' => true,
                'requires_review' => $plan['metadata']['requires_review'] ?? false,
                ...array_intersect_key($plan['metadata'], array_flip(['edge_word_timings_error', 'edge_word_timings_window', 'structure_segments'])),
                ...($evidenceRequest === [] ? [] : ['pending_evidence' => $evidenceRequest]),
            ];
        } catch (Throwable $exception) {
            return ['mode' => 'error', 'error' => $exception::class.': '.$exception->getMessage()];
        } finally {
            DB::rollBack();
        }
    }
}
