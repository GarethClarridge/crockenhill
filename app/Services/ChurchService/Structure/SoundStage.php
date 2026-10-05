<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Services\Media\Audio\AudioTimeline;

/**
 * The rules that judge detected sections against the recording's sound, in their one order.
 *
 * Detection, the evaluation harness and the banked-structure flag recompute all run it, so the
 * order cannot drift between them:
 *  - dead feed first, so a song edge in digital zero is cut back before anything widens it, and
 *    its dropouts become the barriers the song rules may not cross;
 *  - song widening and proposals, from sustained sound and from the audio timeline;
 *  - the spoken-edge trim, after widening, so a song that grew is judged at its new edges;
 *  - the mistyped-sung and sung-inside-sermon passes, so a section still typed as something
 *    else is one no song claimed;
 *  - untranscribed speech before a section last, once every song's end is settled.
 */
final readonly class SoundStage
{
    public function __construct(
        private DeadFeedInsideSection $deadFeed,
        private SustainedSoundSongSections $sustainedSound,
        private SongSpeechEdges $speechEdges,
        private MistypedSungSections $mistypedSung,
        private SungSpanInsideSermon $sungSpanInsideSermon,
        private UntranscribedSpeechBeforeSection $untranscribedSpeech,
    ) {}

    /**
     * @param  string  $rmsLogContent  Raw contents of the rms_log_path artifact
     * @param  bool  $recordingOmitsSongs  A concatenated recording had its songs cut out before assembly
     * @param  AudioTimeline|null  $audioTimeline  Null only when re-deriving flags from banked
     *                                             structure: see {@see SustainedSoundSongSections::apply()}
     */
    public function apply(
        ServiceStructure $structure,
        string $rmsLogContent,
        ChurchServiceTranscript $transcript,
        bool $recordingOmitsSongs,
        ?AudioTimeline $audioTimeline,
    ): ServiceStructure {
        $structure = $this->deadFeed->apply($structure, $rmsLogContent);
        $structure = $this->sustainedSound->apply(
            $structure,
            $rmsLogContent,
            $recordingOmitsSongs,
            $audioTimeline,
            $this->deadFeed->dropouts($rmsLogContent),
        );
        $structure = $this->speechEdges->apply($structure, $rmsLogContent, $recordingOmitsSongs);
        $structure = $this->mistypedSung->apply($structure, $rmsLogContent, $transcript);

        $structure = $this->sungSpanInsideSermon->apply($structure, $rmsLogContent, $transcript);

        return $this->untranscribedSpeech->apply($structure, $transcript, $audioTimeline);
    }
}
