<?php

declare(strict_types=1);

namespace App\Services\ChurchService\Structure;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceSectionMetadata;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionType;
use App\Jobs\PrepareSectionPublicationCandidates;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\SectionPublication\SongLyricsOutsideSection;
use App\Services\Media\Audio\RmsAnalysisService;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Services\Media\Audio\SustainedSound;
use App\Support\ServiceArtifactDisk;
use Illuminate\Support\Facades\Storage;

/**
 * Moves a song's edge out over its own lines sung just beside it.
 *
 * {@see SongLyricsOutsideSection} holds a clip whose own lines are sung in the neighbouring
 * section or in unsectioned time (1291's opening inside the song before it, 1108's chorus
 * inside the prayer after it). This corrects the edge where the evidence is strong, after song
 * matching, so both songs' lyrics are known. It acts only on an edge that check holds, which is
 * the population the replay below measured; a quotation the check releases over speech is
 * never absorbed. What it declines stays held: the correction only has to be right when it
 * acts, and the hold re-checks the corrected span. It moves bounds and nothing else, so
 * {@see PrepareSectionPublicationCandidates} re-cuts the clip on its changed media
 * signature, and it leaves a section an operator has published, approved or rejected alone:
 * the pipeline never withdraws a decision nobody made.
 *
 * Walking outward from the edge, a transcript line counts as this song when it shares
 * content-word pairs with the song's lyrics and more than with the lyrics of a song whose
 * section holds it. The walk stops at an announcement, at a line the other song explains as
 * well, at more than {@see self::MAXIMUM_OTHER_LINES} other lines in a row, or at a pause
 * longer than {@see self::MAXIMUM_PAUSE_SECONDS}. At least {@see self::MINIMUM_LINES} lines
 * move the edge to the outermost; a single line moves it only when it reaches no further than
 * {@see self::NUDGE_SECONDS} past the edge. The neighbour keeps its span: overlapping sections
 * are benign, and a wrong extension then costs the clip a few seconds instead of the neighbour
 * its content.
 *
 * A single line that begins within {@see self::NUDGE_SECONDS} of the edge but runs further
 * (1291's "Your goodness will lead me home.", 4.5 s into the reading) moves the edge only in
 * song order: it is the catalogue line next to the clip's own edge line, and not that line
 * again. Pair counts alone could not tell it from a leader speaking the song's words (962's
 * "teach us to pray", 1326's "God's word that endures forever"); order could, on all 26 held
 * edges of 2026-10-29, and 1120's end it moves was heard right. The neighbour keeps its span
 * here too: a cue's times run early over a held note (1291's next cue is stamped 5 s before its
 * speech), so a spoken part following the song takes its start from the song's own end rule
 * ({@see CueSafeExtractionPlan}), not from these lines.
 *
 * Replayed over the 42 held edges on 2026-09-24 against fresh audio: 10 of the 12 clips missing
 * 5 s or more were corrected, recovering 209 of 353 s without overshooting. 2 of 17 false
 * alarms moved (1305, 7 s of speech; 1262, 6 s of the previous song) and the nudge moved 5 more
 * by at most 2.2 s. Sustained sound decides which edges are held, but not how far a held edge
 * moves: line by line it did not separate the cases (1291's sung lines read as unsustained).
 * The operator accepted all three holder groups and the nudge.
 */
final class SongLyricEdgeExtension
{
    public const METADATA_KEY = 'song_lyric_edge_extension';

    private const DECIDED = [
        ServiceSectionPublicationStatus::Published,
        ServiceSectionPublicationStatus::Approved,
        ServiceSectionPublicationStatus::Rejected,
    ];

    private const WINDOW_SECONDS = 90.0;

    private const MINIMUM_LINES = 2;

    private const MAXIMUM_OTHER_LINES = 2;

    private const MAXIMUM_PAUSE_SECONDS = 15.0;

    private const NUDGE_SECONDS = 3.0;

    /** A line repeated more often than this inside the window is a transcription loop (§4.3). */
    private const MAXIMUM_LINE_REPEATS = 3;

    /**
     * The leader speaking between a quoted verse and the song: announcing it, calling the church
     * to stand or pray, or introducing a reading. Wider than the hold's announcement pattern,
     * which only has to recognise the song being named.
     */
    private const STOP_PATTERN = '/(going to sing|let\'?s (stand|sing|pray)|let us (stand|sing|pray)|shall we sing|we\'?ll sing|remain standing|sit down|hymn number|number \d|verse reads|first verse|next hymn|our (next|closing|opening) (hymn|song)|in prayer|word of prayer|reading|the title of|this hymn|this song|we\'?re going to)/i';

    public function __construct(
        private readonly ServiceTranscriptReader $transcriptReader,
        private readonly SongLyricsOutsideSection $lyricsOutsideSection,
        private readonly RmsAnalysisService $rmsAnalysisService,
    ) {}

    /**
     * @return int How many edges moved
     */
    public function extend(MediaProcessingLog $processingLog): int
    {
        $transcript = $this->transcriptReader->tryRead($processingLog);

        if ($transcript === null || $transcript->cues === []) {
            return 0;
        }

        $sound = $this->sustainedSound($processingLog);
        $sections = $processingLog->serviceSections()->with('churchServiceItem')->orderBy('start_time')->get()->all();
        $songIds = array_values(array_unique(array_filter(array_map(
            static fn (ServiceSection $section): ?int => $section->section_type === ServiceSectionType::Song ? $section->resolvedSongId() : null,
            $sections,
        ))));
        $lyricsPlain = Song::query()->whereKey($songIds)->pluck('lyrics_plain', 'id');
        $lyrics = $lyricsPlain->map(static fn (?string $text): array => SongLyricsOutsideSection::wordPairs($text))->all();
        $lyricLines = $lyricsPlain->map(static fn (?string $text): array => array_map(
            static fn (string $line): array => SongLyricsOutsideSection::wordPairs($line),
            preg_split('/\R/', (string) $text) ?: [],
        ))->all();
        $moved = 0;

        foreach ($sections as $section) {
            $songId = $section->section_type === ServiceSectionType::Song ? $section->resolvedSongId() : null;

            if ($songId === null || ($lyrics[$songId] ?? []) === [] || in_array($section->publication_status, self::DECIDED, true)) {
                continue;
            }

            $heldEdges = array_column(array_filter(
                $this->lyricsOutsideSection->observe($section, $transcript, $sound),
                static fn (array $observation): bool => $observation['risk'],
            ), 'edge');
            $others = array_values(array_filter($sections, static fn (ServiceSection $other): bool => $other->id !== $section->id));
            $extensions = [];

            foreach ($heldEdges as $edge) {
                $extension = $this->extensionAt($edge, $section, $transcript, $others, $lyrics[$songId], $lyrics, $lyricLines[$songId]);

                if ($extension !== null) {
                    $extensions[] = $extension;
                }
            }

            if ($extensions !== []) {
                $this->apply($section, $extensions);
                $moved += count($extensions);
            }
        }

        return $moved;
    }

    /**
     * @param  'before'|'after'  $edge
     * @param  list<ServiceSection>  $others
     * @param  array<string, true>  $ownPairs
     * @param  array<int, array<string, true>>  $lyrics
     * @param  list<array<string, true>>  $ownLines
     * @return array{edge: 'before'|'after', from: float, to: float, lines: int, in_order: bool}|null
     */
    private function extensionAt(string $edge, ServiceSection $section, ChurchServiceTranscript $transcript, array $others, array $ownPairs, array $lyrics, array $ownLines): ?array
    {
        $before = $edge === 'before';
        $edgeTime = $before ? (float) $section->start_time : (float) $section->end_time;
        $window = array_values(array_filter(
            $transcript->cues,
            static fn (array $cue): bool => $before
                ? $cue['start'] < $edgeTime && $cue['start'] >= $edgeTime - self::WINDOW_SECONDS
                : $cue['start'] >= $edgeTime && $cue['start'] < $edgeTime + self::WINDOW_SECONDS,
        ));
        usort($window, static fn (array $a, array $b): int => $before ? $b['start'] <=> $a['start'] : $a['start'] <=> $b['start']);
        $repeats = array_count_values(array_map(fn (array $cue): string => $this->normalised($cue['text']), $window));

        $lines = 0;
        $outermost = null;
        $innermost = null;
        $otherLines = 0;
        $lastTime = $edgeTime;

        foreach ($window as $cue) {
            if (abs($cue['start'] - $lastTime) > self::MAXIMUM_PAUSE_SECONDS || preg_match(self::STOP_PATTERN, $cue['text']) === 1) {
                break;
            }

            $lastTime = $cue['start'];

            if ($repeats[$this->normalised($cue['text'])] > self::MAXIMUM_LINE_REPEATS) {
                $otherLines++;
            } else {
                $linePairs = SongLyricsOutsideSection::wordPairs($cue['text']);
                $own = count(array_intersect_key($linePairs, $ownPairs));
                $holderSongId = $this->holderSongId($others, $cue['start']);
                $holderShared = $holderSongId === null ? 0 : count(array_intersect_key($linePairs, $lyrics[$holderSongId] ?? []));

                if ($holderShared > 0 && $holderShared >= $own) {
                    break;
                }

                if ($own > 0) {
                    $lines++;
                    $outermost = $cue;
                    $innermost ??= $cue;
                    $otherLines = 0;

                    continue;
                }

                $otherLines++;
            }

            if ($otherLines > self::MAXIMUM_OTHER_LINES) {
                break;
            }
        }

        if ($outermost === null || $innermost === null) {
            return null;
        }

        $inOrder = false;

        if ($lines < self::MINIMUM_LINES) {
            $reach = $before ? $edgeTime - $innermost['start'] : $innermost['end'] - $edgeTime;
            $gap = $before ? $edgeTime - $innermost['end'] : $innermost['start'] - $edgeTime;

            if ($innermost !== $window[0]) {
                return null;
            }

            if ($reach > self::NUDGE_SECONDS) {
                $inOrder = $gap <= self::NUDGE_SECONDS && $this->followsInSongOrder($edge, $section, $transcript, $innermost, $ownLines);

                if (! $inOrder) {
                    return null;
                }
            }
        }

        $to = $before ? (float) $outermost['start'] : (float) $outermost['end'];

        if ($before ? $to >= $edgeTime : $to <= $edgeTime) {
            return null;
        }

        return ['edge' => $edge, 'from' => $edgeTime, 'to' => $to, 'lines' => $lines, 'in_order' => $inOrder];
    }

    /**
     * Whether a line outside the clip is the catalogue line next to the clip's own edge line:
     * after it for an end, before it for a start. A line that best matches the same catalogue
     * lines as the clip's edge line is that line again, not the next one.
     *
     * @param  'before'|'after'  $edge
     * @param  array{start: float, end: float, text: string}  $outside
     * @param  list<array<string, true>>  $ownLines
     */
    private function followsInSongOrder(string $edge, ServiceSection $section, ChurchServiceTranscript $transcript, array $outside, array $ownLines): bool
    {
        $inside = array_values(array_filter(
            $transcript->cues,
            static fn (array $cue): bool => $cue['start'] >= (float) $section->start_time && $cue['start'] < (float) $section->end_time,
        ));
        $edgeCue = $edge === 'after' ? end($inside) : reset($inside);

        if ($edgeCue === false) {
            return false;
        }

        $insideLines = $this->bestLyricLines($edgeCue['text'], $ownLines);
        $outsideLines = $this->bestLyricLines($outside['text'], $ownLines);

        if (array_intersect($insideLines, $outsideLines) !== []) {
            return false;
        }

        $step = $edge === 'after' ? 1 : -1;

        return array_any($insideLines, static fn (int $line): bool => in_array($line + $step, $outsideLines, true));
    }

    /**
     * The catalogue lines sharing the most content-word pairs with a transcript line.
     *
     * @param  list<array<string, true>>  $ownLines
     * @return list<int>
     */
    private function bestLyricLines(string $text, array $ownLines): array
    {
        $pairs = SongLyricsOutsideSection::wordPairs($text);
        $shared = array_map(static fn (array $line): int => count(array_intersect_key($pairs, $line)), $ownLines);
        $most = $shared === [] ? 0 : max($shared);

        return $most === 0 ? [] : array_keys(array_filter($shared, static fn (int $count): bool => $count === $most));
    }

    /**
     * @param  list<array{edge: 'before'|'after', from: float, to: float, lines: int, in_order: bool}>  $extensions
     */
    private function apply(ServiceSection $section, array $extensions): void
    {
        $metadata = $section->metadata?->toArray() ?? [];
        $recorded = is_array($metadata[self::METADATA_KEY] ?? null) ? $metadata[self::METADATA_KEY] : [];

        foreach ($extensions as $extension) {
            if ($extension['edge'] === 'before') {
                $section->start_time = $extension['to'];
            } else {
                $section->end_time = $extension['to'];
            }

            if (! $extension['in_order']) {
                unset($extension['in_order']);
            }

            $recorded[] = [...$extension, 'extended_at' => now()->toIso8601String()];
        }

        $metadata[self::METADATA_KEY] = $recorded;
        $section->duration = max(0.0, (float) $section->end_time - (float) $section->start_time);
        $section->metadata = ServiceSectionMetadata::fromArray($metadata);
        $section->save();
    }

    private function sustainedSound(MediaProcessingLog $processingLog): ?SustainedSound
    {
        $rmsLogPath = $processingLog->rms_log_path;

        if (! is_string($rmsLogPath) || $rmsLogPath === '') {
            return null;
        }

        $disk = ServiceArtifactDisk::for($rmsLogPath);

        if (! Storage::disk($disk)->exists($rmsLogPath)) {
            return null;
        }

        return SustainedSound::fromRmsLog((string) Storage::disk($disk)->get($rmsLogPath), $this->rmsAnalysisService);
    }

    /**
     * @param  list<ServiceSection>  $others
     */
    private function holderSongId(array $others, float $time): ?int
    {
        foreach ($others as $other) {
            if ((float) $other->start_time <= $time && (float) $other->end_time > $time) {
                return $other->section_type === ServiceSectionType::Song ? $other->resolvedSongId() : null;
            }
        }

        return null;
    }

    private function normalised(string $text): string
    {
        return trim((string) preg_replace('/[^a-z]+/', ' ', mb_strtolower($text)));
    }
}
