<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\SungSpanInsideSermon;
use App\Services\Media\Audio\RmsAnalysisService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A hymn wholly inside the sermon section (sermon 885, run 949 §723).
 *
 * Measured 2026-09-16 over 437 runs (`sungpass-20260916-measure.json`): 272 sustained spans of
 * 30 s or more sit inside sermons and outside every song section, nearly all ordinary preaching
 * at 104–181 wpm. Under 40 wpm leaves two, stable from <20 to <60: run 949's closing hymn (0 cues,
 * inside an unobservable window) and run 1014 §1300's "Thank you ×4" ASR artefact.
 */
class SungSpanInsideSermonTest extends TestCase
{
    private SungSpanInsideSermon $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.segmentation.adaptive_thresholds.enabled', false);
        Config::set('media-processing.segmentation.rms_threshold', -45.0);

        $this->service = new SungSpanInsideSermon(new RmsAnalysisService);
    }

    /** Run 949's shape: preaching, then a hymn the transcript never caught, inside one sermon. */
    #[Test]
    public function it_flags_a_sermon_holding_a_sung_span_nobody_transcribed(): void
    {
        $sermon = $this->applyTo(sung: [1400, 1560], sungWords: 0);

        $this->assertContains(ServiceStructureValidator::FLAG_SERMON_CONTAINS_SUNG_SPAN, $sermon->reviewFlags);
        $this->assertNotEmpty(array_filter($sermon->notes, static fn (string $note): bool => str_contains($note, 'Sung span')));
    }

    /** Preaching can read as sustained against a compressed floor; its word rate gives it away. */
    #[Test]
    public function it_leaves_sustained_preaching_at_speaking_rate_alone(): void
    {
        $sermon = $this->applyTo(sung: [1400, 1560], sungWords: 400);

        $this->assertNotContains(ServiceStructureValidator::FLAG_SERMON_CONTAINS_SUNG_SPAN, $sermon->reviewFlags);
    }

    #[Test]
    public function it_leaves_a_span_a_song_section_already_holds(): void
    {
        $sermon = $this->applyTo(sung: [1400, 1560], sungWords: 0, songSection: [1400.0, 1560.0]);

        $this->assertNotContains(ServiceStructureValidator::FLAG_SERMON_CONTAINS_SUNG_SPAN, $sermon->reviewFlags);
    }

    #[Test]
    public function it_leaves_a_sung_span_under_thirty_seconds_alone(): void
    {
        $sermon = $this->applyTo(sung: [1400, 1420], sungWords: 0);

        $this->assertNotContains(ServiceStructureValidator::FLAG_SERMON_CONTAINS_SUNG_SPAN, $sermon->reviewFlags);
    }

    #[Test]
    public function it_leaves_a_sermon_of_ordinary_preaching_alone(): void
    {
        $sermon = $this->applyTo(sung: null, sungWords: 0);

        $this->assertSame([], $sermon->reviewFlags);
    }

    /**
     * A 1000–1800 s sermon of speech at 140 wpm, optionally holding a sung span.
     *
     * @param  array{0: int, 1: int}|null  $sung
     * @param  array{0: float, 1: float}|null  $songSection
     */
    private function applyTo(?array $sung, int $sungWords, ?array $songSection = null): ServiceStructureSection
    {
        $sections = [$this->section('song', 900.0, 990.0), $this->section('sermon', 1000.0, 1800.0)];

        if ($songSection !== null) {
            $sections[] = $this->section('song', $songSection[0], $songSection[1]);
        }

        $spans = [[900, 990, 'sung'], [1000, 1800, 'speech']];
        $cues = $this->cues(1000.0, 1800.0, 140.0, $sung);

        if ($sung !== null) {
            $spans = [[900, 990, 'sung'], [1000, $sung[0], 'speech'], [$sung[0], $sung[1], 'sung'], [$sung[1], 1800, 'speech']];
            $cues = [...$cues, ...$this->cues((float) $sung[0], (float) $sung[1], $sungWords / (($sung[1] - $sung[0]) / 60.0), null)];
        }

        usort($cues, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $applied = $this->service->apply(
            ServiceStructure::fromSections($sections),
            $this->rmsLog($spans),
            ChurchServiceTranscript::fromCues($cues, 1900.0, 'mock'),
        );

        foreach ($applied->sections as $section) {
            if ($section->type->value === 'sermon') {
                return $section;
            }
        }

        $this->fail('The sermon section went missing.');
    }

    /**
     * Four-second cues at the given word rate, skipping an excluded span.
     *
     * @param  array{0: int, 1: int}|null  $except
     * @return list<array{start: float, end: float, text: string}>
     */
    private function cues(float $start, float $end, float $wordsPerMinute, ?array $except): array
    {
        $cues = [];
        $perCue = (int) round($wordsPerMinute / 15.0);

        for ($time = $start; $time < $end && $perCue > 0; $time += 4.0) {
            if ($except !== null && $time >= $except[0] && $time < $except[1]) {
                continue;
            }

            $cues[] = ['start' => $time, 'end' => $time + 4.0, 'text' => trim(str_repeat('grace ', $perCue))];
        }

        return $cues;
    }

    private function section(string $type, float $start, float $end): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray(['type' => $type, 'start_time' => $start, 'end_time' => $end, 'confidence' => 0.9]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }

    /**
     * The sustained-sound tests' RMS log: singing holds -18 dB; speech runs 2.5 s at -25 dB then
     * pauses 0.5 s; anything unlisted is silence.
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
