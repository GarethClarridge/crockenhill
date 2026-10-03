<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ChurchServiceTranscript;
use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureDrawExecutor;
use App\Services\ChurchService\Structure\TranscriptCueBoundaries;
use App\Services\ChurchService\Structure\ValidationContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioTimelineFixture;
use Tests\TestCase;

class TranscriptCueBoundariesTest extends TestCase
{
    #[Test]
    public function saved_draw_reading_end_preserves_run_949s_last_word(): void
    {
        $section = $this->refine(ServiceSectionType::BibleReading, 2443, 2447, [
            ['start' => 2443.94, 'end' => 2447.26, 'text' => 'Spirit'],
        ])->sections[0];

        $this->assertSame(2447.26, $section->endTime);
    }

    #[Test]
    public function saved_draw_start_uses_the_exact_cue_start(): void
    {
        $section = $this->refine(ServiceSectionType::Prayer, 2443, 2447, [
            ['start' => 2443.94, 'end' => 2447.26, 'text' => 'Amen'],
        ])->sections[0];

        $this->assertSame(2443.94, $section->startTime);
    }

    #[Test]
    public function every_section_type_restores_unique_cue_edges(): void
    {
        foreach (ServiceSectionType::cases() as $type) {
            $section = $this->refine($type, 2443, 2447, [
                ['start' => 2443.94, 'end' => 2447.26, 'text' => 'Complete words'],
            ])->sections[0];
            $this->assertSame([2443.94, 2447.26], [$section->startTime, $section->endTime], $type->value);
        }
    }

    #[Test]
    public function adjacent_sections_map_to_separate_exact_cues_without_overlap(): void
    {
        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 2443.94, 'end' => 2447.26, 'text' => 'Spirit'],
            ['start' => 2447.28, 'end' => 2450.66, 'text' => 'Let us pray'],
        ], 3000, ChurchServiceTranscript::SOURCE_MOCK);
        $structure = ServiceStructure::fromSections([
            new ServiceStructureSection(ServiceSectionType::BibleReading, null, 2443, 2447, 0.9, null, null, null),
            new ServiceStructureSection(ServiceSectionType::Prayer, null, 2447, 2450, 0.9, null, null, null),
        ]);
        $result = app(TranscriptCueBoundaries::class)->apply($structure, $transcript);

        $this->assertSame(2447.26, $result->sections[0]->endTime);
        $this->assertSame(2447.28, $result->sections[1]->startTime);
        $this->assertLessThanOrEqual($result->sections[1]->startTime, $result->sections[0]->endTime);
    }

    #[Test]
    public function ambiguous_and_missing_cue_matches_keep_proposals_and_explain_why(): void
    {
        $ambiguous = $this->refine(ServiceSectionType::Prayer, 2443, 2447, [
            ['start' => 2443.10, 'end' => 2447.10, 'text' => 'First'],
            ['start' => 2443.90, 'end' => 2447.90, 'text' => 'Second'],
        ])->sections[0];
        $missing = $this->refine(ServiceSectionType::Prayer, 2440, 2450, [
            ['start' => 2443.94, 'end' => 2447.26, 'text' => 'Amen'],
        ])->sections[0];
        $this->assertSame([2443.0, 2447.0], [$ambiguous->startTime, $ambiguous->endTime]);
        $this->assertStringContainsString('2 matching cues; left unchanged', implode(' ', $ambiguous->notes));
        $this->assertSame([2440.0, 2450.0], [$missing->startTime, $missing->endTime]);
        $this->assertStringContainsString('0 matching cues; left unchanged', implode(' ', $missing->notes));
    }

    #[Test]
    public function fractional_proposals_are_not_reinterpreted_as_displayed_cue_times(): void
    {
        $section = $this->refine(ServiceSectionType::Prayer, 2443.5, 2447.5, [
            ['start' => 2443.94, 'end' => 2447.26, 'text' => 'Amen'],
        ])->sections[0];
        $this->assertSame([2443.5, 2447.5], [$section->startTime, $section->endTime]);
    }

    #[Test]
    public function music_intro_and_sustained_sound_still_widen_a_song_after_cue_mapping(): void
    {
        config(['media-processing.segmentation.adaptive_thresholds.enabled' => false,
            'media-processing.segmentation.rms_threshold' => -45.0]);
        $lines = [];
        for ($tenth = 0; $tenth < 9000; $tenth++) {
            $time = $tenth / 10;
            $level = $time >= 300 && $time < 600 ? -18.0 : (fmod($time, 3.0) < 2.5 ? -25.0 : -60.0);
            $lines[] = sprintf('frame:%d pts:%d pts_time:%.1f', $tenth, $tenth * 800, $time);
            $lines[] = sprintf('lavfi.astats.Overall.RMS_level=%.1f', $level);
        }
        $section = $this->refine(ServiceSectionType::Song, 340, 570, [
            ['start' => 340.94, 'end' => 570.26, 'text' => 'Amazing grace how sweet the sound'],
        ], implode("\n", $lines), AudioTimelineFixture::payload([[300, 600, 0.95, 0.05]], 3000))->sections[0];

        $this->assertStringContainsString('exact cue start 340.940s', implode(' ', $section->notes));
        $this->assertStringContainsString('exact cue end 570.260s', implode(' ', $section->notes));
        $this->assertLessThan(340.94, $section->startTime);
        $this->assertGreaterThan(570.26, $section->endTime);
        $this->assertEqualsWithDelta(300, $section->startTime, 10);
        $this->assertEqualsWithDelta(600, $section->endTime, 10);
    }

    /**
     * @param  list<array{start: float, end: float, text: string}>  $cues
     * @param  array<string, mixed>|null  $timeline
     */
    private function refine(ServiceSectionType $type, float $start, float $end, array $cues, ?string $rms = null, ?array $timeline = null): ServiceStructure
    {
        $transcript = ChurchServiceTranscript::fromCues($cues, 3000, ChurchServiceTranscript::SOURCE_MOCK);
        $input = [
            'transcript' => $transcript->toArray(),
            'audio_timeline' => $timeline ?? AudioTimelineFixture::payload([], 3000),
            'rms_log' => $rms,
            'validation_context' => ServiceStructureDrawExecutor::contextSnapshot(ValidationContext::for($transcript)),
        ];
        $raw = ServiceStructure::fromSections([
            new ServiceStructureSection($type, null, $start, $end, 0.9, null, null, null),
        ]);

        return app(ServiceStructureDrawExecutor::class)->refineAndValidate($input, $raw)['refined'];
    }
}
