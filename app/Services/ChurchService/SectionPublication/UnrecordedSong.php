<?php

declare(strict_types=1);

namespace App\Services\ChurchService\SectionPublication;

use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\DeadFeedInsideSection;
use App\Services\Media\Audio\AudioTimeline;
use App\Services\Media\Audio\RmsAnalysisService;

/**
 * A song the recording never captured: its section is only the announcement.
 *
 * Operator 11-04 (plan §4.1a item 7): a song with no sung audio keeps its title in the order of
 * service but makes no clip. Its section holds no music ({@see SongSectionWithoutMusic}), and the
 * recording gives the song nowhere else to be: it ends within {@see self::RECORDING_END_SECONDS}
 * of the section, or digital zero lasting {@see DeadFeedInsideSection::MINIMUM_SECONDS} or more
 * begins where the section ends (E14 moves a song's end back to such a run).
 *
 * Measured 2026-11-04 (`storage/scratch/item7/`): of the 24 timed song sections with no music
 * window, 14 meet this, and every one is the leader announcing the hymn ("Let's stand and sing").
 * 11 end within 5.1 s of the recording's end; 1377 §4781 and 18423 §335933/§335939 run into 123 to
 * 219 s of digital zero. The other 10 are kept and still held by the no-music rule, among them
 * unaccompanied singing the classifier hears as no music (1252, 1352, 1138), which only a dead
 * stretch after the section rules out.
 */
final class UnrecordedSong
{
    /** The longest gap measured is 5.1 s (1142); no song is sung in 10. */
    public const float RECORDING_END_SECONDS = 10.0;

    /** A run of zeros begins a fraction of a second either side of the edge E14 placed on it. */
    private const float EDGE_TOLERANCE_SECONDS = 1.0;

    public function __construct(
        private readonly SongSectionWithoutMusic $sectionWithoutMusic,
        private readonly DeadFeedInsideSection $deadFeed,
        private readonly RmsAnalysisService $rmsAnalysisService,
    ) {}

    /**
     * Why the song was never recorded, or null when it may have been or cannot be judged.
     *
     * @return array{reason: 'recording_ends'|'feed_muted', detail: string}|null
     */
    public function observe(ServiceSection $section): ?array
    {
        $log = $section->processingLog;
        $music = $this->sectionWithoutMusic->observe($section, $this->timeline($log->storedAudioTimelineContents()));

        if ($music === null || ! $music['risk']) {
            return null;
        }

        $rmsLog = $log->storedRmsLogContents();
        $samples = $rmsLog === null ? [] : $this->rmsAnalysisService->extractRmsData($rmsLog);

        if ($samples === []) {
            return null;
        }

        $end = (float) $section->end_time;
        $recordingEnd = $samples[array_key_last($samples)]['time'];

        if ($recordingEnd - $end <= self::RECORDING_END_SECONDS) {
            return ['reason' => 'recording_ends', 'detail' => sprintf(
                'No music is heard in this song section, and the recording ends %.1f s after it: the song was never recorded.',
                max(0.0, $recordingEnd - $end),
            )];
        }

        foreach ($this->deadFeed->digitalZeroRuns($samples) as [$from, $to]) {
            if (abs($from - $end) <= self::EDGE_TOLERANCE_SECONDS) {
                return ['reason' => 'feed_muted', 'detail' => sprintf(
                    'No music is heard in this song section, and the feed carried no samples for %.0f s after it (%.1f–%.1f s): the song was sung while the recording was muted.',
                    $to - $from,
                    $from,
                    $to,
                )];
            }
        }

        return null;
    }

    private function timeline(?string $contents): ?AudioTimeline
    {
        if ($contents === null) {
            return null;
        }

        try {
            return AudioTimeline::fromJson($contents);
        } catch (\UnexpectedValueException) {
            return null;
        }
    }
}
