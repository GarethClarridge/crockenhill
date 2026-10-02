<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\Sermon\SermonCutProbe;

/**
 * Composes an ensemble, drops the filler questions whose answer cannot change the cut, and asks
 * about the talk edges agreement cannot check ({@see TalkEdgeChecks}).
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
        private readonly TalkEdgeChecks $talkEdgeChecks,
    ) {}

    /**
     * @param  array<int, ValidationResult>  $draws
     */
    public function compose(array $draws, ?ChurchServiceTranscript $transcript, ?MediaProcessingLog $log): EnsembleComposition
    {
        return $this->talkEdgeChecks->apply($this->composeCutAware($draws, $transcript, $log), $draws);
    }

    /**
     * @param  array<int, ValidationResult>  $draws
     */
    private function composeCutAware(array $draws, ?ChurchServiceTranscript $transcript, ?MediaProcessingLog $log): EnsembleComposition
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

            if ($this->cutNeutral($draws, $transcript, $log, $groups)) {
                array_push($neutral, ...$groups);
            }
        }

        return $neutral === [] ? $composition : $this->composer->compose($draws, $transcript, $neutral);
    }

    /**
     * Whether writing or leaving out each of the dispute's groups leaves the cut unchanged.
     *
     * @param  array<int, ValidationResult>  $draws
     * @param  list<int>  $groups
     */
    private function cutNeutral(array $draws, ChurchServiceTranscript $transcript, MediaProcessingLog $log, array $groups): bool
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
                $plan = $this->cutProbe->asWritten($log, $structure, $transcript);

                if (($plan['mode'] ?? 'error') === 'error') {
                    return false;
                }

                $cuts[] = json_encode([$plan['mode'], $plan['segments'] ?? null], JSON_THROW_ON_ERROR);
            }
        }

        return count(array_unique($cuts)) === 1;
    }
}
