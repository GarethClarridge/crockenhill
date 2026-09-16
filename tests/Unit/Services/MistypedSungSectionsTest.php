<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Services\ChurchService\Structure\MistypedSungSections;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\Media\Audio\RmsAnalysisService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Congregational singing typed as something else.
 *
 * Measured over the 437-run corpus on 2026-09-16 (`sungpass-20260916-measure.json`). Of the five
 * sections §4.1a named as sung items, only §1301 — "Lo He Comes With Clouds Descending", 290 s
 * typed `other` on run 1014 — is actually sung; the rest are a spoken psalm reading, hymn verses
 * read as a prayer, an ASR loop, and a hymn announced but never sung.
 *
 * Sound alone cannot find it: 48 non-song sections read as fully active, nearly all of them
 * notices and welcomes at conversational speed. Word rate is the second axis — §1301 runs at 44
 * words a minute against 88–227 for the spoken sections — and the two together leave 13 sections
 * corpus-wide, of which exactly one is absorbed into a sermon's span. That absorption test lives
 * in the extraction planner; this class only says which sections read as sung.
 */
class MistypedSungSectionsTest extends TestCase
{
    private MistypedSungSections $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        Config::set('media-processing.segmentation.rms_threshold', -45.0);

        $this->service = new MistypedSungSections(new RmsAnalysisService);
    }

    /** §1301's shape: a hymn typed `other`, sung without a break, and barely transcribed. */
    #[Test]
    public function it_flags_a_section_typed_otherwise_that_is_actually_sung(): void
    {
        $flags = $this->applyTo('other', 300.0, 590.0, words: 200, kind: 'sung');

        $this->assertContains(ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG, $flags);
    }

    /**
     * The population sound alone would have caught. Notices and welcomes run fully active against
     * a compressed noise floor, so only the word rate separates them from singing.
     */
    #[Test]
    public function it_leaves_a_sustained_section_at_speaking_rate_alone(): void
    {
        $flags = $this->applyTo('notices', 300.0, 590.0, words: 600, kind: 'sung');

        $this->assertNotContains(ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG, $flags);
    }

    /** A song already typed as a song is not mistyped, whatever its sound. */
    #[Test]
    public function it_leaves_a_song_section_alone(): void
    {
        $flags = $this->applyTo('song', 300.0, 590.0, words: 200, kind: 'sung');

        $this->assertNotContains(ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG, $flags);
    }

    /**
     * The flag must never reach a sermon section. `SermonAutoExtractionPolicy` permits automatic
     * extraction only when every flag on the chosen section is registered as non-disqualifying,
     * so an unregistered flag landing here would quietly stop sermons extracting.
     */
    #[Test]
    public function it_never_flags_a_sermon_section(): void
    {
        $flags = $this->applyTo('sermon', 300.0, 590.0, words: 200, kind: 'sung');

        $this->assertNotContains(ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG, $flags);
    }

    /** Too short to be a sung item, and short spans are where the sound measure is least sure. */
    #[Test]
    public function it_leaves_a_short_section_alone(): void
    {
        $flags = $this->applyTo('other', 300.0, 330.0, words: 20, kind: 'sung');

        $this->assertNotContains(ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG, $flags);
    }

    /** Speech-shaped sound is not singing however little of it the transcript caught. */
    #[Test]
    public function it_leaves_a_word_sparse_section_whose_sound_is_speech_alone(): void
    {
        $flags = $this->applyTo('other', 300.0, 590.0, words: 200, kind: 'speech');

        $this->assertNotContains(ServiceStructureValidator::FLAG_SECTION_READS_AS_SUNG, $flags);
    }

    /**
     * Run one section through the rule and return the review flags it comes back with.
     *
     * @param  'sung'|'speech'  $kind
     * @return list<string>
     */
    private function applyTo(string $type, float $start, float $end, int $words, string $kind): array
    {
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 290.0),
            $this->section($type, $start, $end),
            $this->section('prayer', $end + 10.0, $end + 300.0),
        ]);

        $applied = $this->service->apply(
            $structure,
            $this->rmsLog([[0, (int) $start - 10, 'speech'], [(int) $start, (int) $end, $kind], [(int) $end + 10, (int) $end + 300, 'speech']]),
            $this->transcript($start, $end, $words),
        );

        return $applied->sections[1]->reviewFlags;
    }

    /** Cues spreading `$words` words evenly across the span, so the word rate is what it says. */
    private function transcript(float $start, float $end, int $words): ChurchServiceTranscript
    {
        $cues = [];
        $perCue = 10;
        $cueCount = max(1, (int) ceil($words / $perCue));
        $step = ($end - $start) / $cueCount;

        for ($index = 0; $index < $cueCount; $index++) {
            $cueStart = $start + ($index * $step);
            $cues[] = [
                'start' => $cueStart,
                'end' => $cueStart + min($step, 4.0),
                'text' => trim(str_repeat('alleluia ', min($perCue, $words - ($index * $perCue)))),
            ];
        }

        return ChurchServiceTranscript::fromCues($cues, $end + 300.0, 'mock');
    }

    private function section(string $type, float $start, float $end): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => 0.9,
        ]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }

    /**
     * An ffmpeg-astats-style RMS log sampled every 0.1 s, matching the sustained-sound tests:
     * singing holds -18 dB without a break; speech runs 2.5 s at -25 dB then pauses 0.5 s at
     * -60 dB (20 pauses a minute); anything not listed is silence.
     *
     * @param  list<array{0: int, 1: int, 2: 'sung'|'speech'}>  $spans
     */
    private function rmsLog(array $spans): string
    {
        $end = max(array_map(static fn (array $span): int => $span[1], $spans));
        $lines = [];

        for ($tenth = 0; $tenth < $end * 10; $tenth++) {
            $time = $tenth / 10;
            $level = -60.0;

            foreach ($spans as [$from, $to, $kind]) {
                if ($time >= $from && $time < $to) {
                    $level = $kind === 'sung' ? -18.0 : (fmod($time, 3.0) < 2.5 ? -25.0 : -60.0);
                }
            }

            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = sprintf('lavfi.astats.Overall.RMS_level=%.1f', $level);
        }

        return implode("\n", $lines)."\n";
    }
}
