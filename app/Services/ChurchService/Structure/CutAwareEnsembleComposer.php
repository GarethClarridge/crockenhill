<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\Sermon\SermonCutProbe;

/**
 * Composes an ensemble and drops the filler questions whose answer cannot change the cut.
 *
 * A disagreement over a welcome, prayer or `other` span is asked only when the sermon's cut
 * differs between its versions (ruled 2026-09-30, extending decision 12). Rather than predict
 * which spans reach the cut, each disputed filler group is written and then left out, and the
 * production resolver plans the cut both ways through {@see SermonCutProbe}. Notices are always
 * asked, since they can stop the sermon being extended. Without a run to plan against, nothing
 * is dropped.
 */
class CutAwareEnsembleComposer
{
    /** Filler whose questions depend on the cut. */
    private const CUT_JUDGED_TYPES = ['welcome', 'prayer', 'other'];

    public function __construct(
        private readonly ServiceStructureEnsembleComposer $composer,
        private readonly SermonCutProbe $cutProbe,
    ) {}

    /**
     * @param  array<int, ValidationResult>  $draws
     * @param  bool  $gatherEvidence  Decode the edge words and answer the sermon-end question each version's
     *                                cut waits on, banking them on the run. Only the pipeline's detection
     *                                gathers; a replay, export or report reads what is banked.
     */
    public function compose(array $draws, ?ChurchServiceTranscript $transcript, ?MediaProcessingLog $log, bool $gatherEvidence = false): EnsembleComposition
    {
        $composition = $this->composeCutAware($draws, $transcript, $log, $gatherEvidence);
        if ($transcript === null || $composition->refused) {
            return $composition;
        }
        $final = app(TranscriptCueBoundaries::class)->finish($composition->structure, $transcript);
        $voters = array_keys(array_filter($draws, static fn (ValidationResult $draw): bool => $draw->passed()));
        sort($voters);

        return app(LoopedSegmentChecks::class)->apply(new EnsembleComposition($final['structure'], [...$composition->disputes, ...$final['questions']], $composition->provenance,
            $composition->degraded, $composition->refused, $composition->validVotes, $composition->degradedReviewed, $composition->majorityDecisions), $transcript, $voters);
    }

    /**
     * @param  array<int, ValidationResult>  $draws
     */
    private function composeCutAware(array $draws, ?ChurchServiceTranscript $transcript, ?MediaProcessingLog $log, bool $gatherEvidence): EnsembleComposition
    {
        $composition = $this->composer->compose($draws, $transcript);

        if (! $log instanceof MediaProcessingLog || ! $transcript instanceof ChurchServiceTranscript || $composition->refused) {
            return $composition;
        }

        $neutral = [];

        // A filler span the majority settled still reaches the skim list only if it can move the cut.
        foreach ([...$composition->disputes, ...$composition->majorityDecisions] as $dispute) {
            $groups = $dispute['groups'] ?? (isset($dispute['group']) ? [$dispute['group']] : []);

            if (! in_array($dispute['type'] ?? null, self::CUT_JUDGED_TYPES, true) || $groups === []) {
                continue;
            }

            if ($this->cutNeutral($draws, $transcript, $log, $groups, $gatherEvidence)) {
                array_push($neutral, ...$groups);
            }
        }

        return $neutral === [] ? $composition : $this->composer->compose($draws, $transcript, $neutral);
    }

    /**
     * Whether writing or leaving out each of the dispute's groups leaves the cut unchanged.
     *
     * Only planned cuts are compared. A version whose edge words are not decoded plans no cut, and
     * two empty plans are not the same cut: counting them equal dropped every filler question at
     * first detection, and comparing one with a planned cut asked a question no later replay could
     * produce (1148, 1150, 1178, 1190 in the 11-02 round). Detection gathers what each version
     * waits on first; a version still waiting is asked.
     *
     * @param  array<int, ValidationResult>  $draws
     * @param  list<int>  $groups
     */
    private function cutNeutral(array $draws, ChurchServiceTranscript $transcript, MediaProcessingLog $log, array $groups, bool $gatherEvidence): bool
    {
        $cuts = [];

        foreach ($groups as $group) {
            foreach ([true, false] as $written) {
                $forced = [$group => $written];

                foreach ($groups as $other) {
                    if ($other !== $group) {
                        $forced[$other] = ! $written;
                    }
                }

                $structure = $this->composer->compose($draws, $transcript, [], $forced)->structure;
                $plan = $gatherEvidence
                    ? $this->cutProbe->asWrittenWithEvidence($log, $structure, $transcript)
                    : $this->cutProbe->asWritten($log, $structure, $transcript);

                if (($plan['mode'] ?? 'error') === 'error' || ($plan['strategy'] ?? null) === 'edge_word_timings_missing') {
                    return false;
                }

                $cuts[] = json_encode([$plan['mode'], $plan['segments'] ?? null], JSON_THROW_ON_ERROR);
            }
        }

        return count(array_unique($cuts)) === 1;
    }
}
