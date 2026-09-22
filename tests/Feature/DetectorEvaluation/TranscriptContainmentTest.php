<?php

declare(strict_types=1);

namespace Tests\Feature\DetectorEvaluation;

use App\Data\SuspectTranscriptBlock;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\DetectorEvaluation\DetectorCaseBookSource;
use App\Services\DetectorEvaluation\DetectorEvaluation;
use App\Services\DetectorEvaluation\FreezeDetectorCaseBook;
use App\Services\DetectorEvaluation\SuspectTranscriptBlockSignals;
use App\Support\DetectorAcceptanceThresholds;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use InvalidArgumentException;
use Tests\TestCase;

class TranscriptContainmentTest extends TestCase
{
    use DatabaseTransactions;

    /** @param array<string, mixed> $span */
    #[Test]
    #[DataProvider('invalidSpans')]
    public function invalid_windows_are_refused(array $span): void
    {
        [$run, $section] = $this->fixture(true);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('span');

        $this->evaluate($run, $section, $span);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidSpans(): array
    {
        return [
            'negative' => [['start' => -1.0, 'end' => 20.0]],
            'empty' => [['start' => 20.0, 'end' => 20.0]],
            'reversed' => [['start' => 21.0, 'end' => 20.0]],
            'missing end' => [['start' => 0.0]],
            'infinite' => [['start' => 0.0, 'end' => INF]],
        ];
    }

    #[Test]
    public function an_unrelated_block_does_not_make_a_clean_window_a_false_positive(): void
    {
        [$run, $section] = $this->fixture(true);

        $case = $this->evaluate($run, $section, ['start' => 10.0, 'end' => 60.0], 'clean');

        $this->assertSame('correctly_silent', $case['outcome']);
    }

    #[Test]
    public function an_overlapping_block_matches_the_reviewed_window(): void
    {
        [$run, $section] = $this->fixture(true);

        $case = $this->evaluate($run, $section, ['start' => 150.0, 'end' => 200.0]);

        $this->assertSame('contained', $case['outcome']);
        $this->assertSame('span', $case['match_level']);
    }

    #[Test]
    public function touching_window_edges_do_not_count_as_overlap(): void
    {
        [$run, $section] = $this->fixture(true);

        $this->assertSame('missed', $this->evaluate($run, $section, ['start' => 180.0, 'end' => 200.0])['outcome']);
    }

    #[Test]
    public function a_recorded_block_does_not_contain_an_unheld_section(): void
    {
        [$run, $section] = $this->fixture(false);

        $case = $this->evaluate($run, $section);

        $this->assertFalse($case['subject_held']);
        $this->assertSame('flagged_unheld', $case['outcome']);
    }

    #[Test]
    public function a_held_section_contains_its_overlapping_block(): void
    {
        [$run, $section] = $this->fixture(true);

        $case = $this->evaluate($run, $section);

        $this->assertTrue($case['subject_held']);
        $this->assertSame('contained', $case['outcome']);
        $this->assertSame('section', $case['match_level']);
    }

    #[Test]
    public function a_block_elsewhere_on_the_run_does_not_match_a_section_case(): void
    {
        [$run, $section] = $this->fixture(true);
        $section->update(['start_time' => 200.0, 'duration' => 100.0]);

        $this->assertSame('missed', $this->evaluate($run, $section)['outcome']);
    }

    #[Test]
    public function a_block_in_an_omitted_hymn_does_not_match_the_delivered_sermon(): void
    {
        [$run, $section] = $this->fixture(true);
        $run->writeProcessingMetadata(static function (array $metadata): array {
            $metadata['sermon_extraction_plan'] = [
                'source' => 'service_sections',
                'mode' => 'concat_spans',
                'segments' => [
                    ['start_time' => 0.0, 'end_time' => 90.0],
                    ['start_time' => 200.0, 'end_time' => 300.0],
                ],
            ];

            return $metadata;
        });

        $this->assertSame('missed', $this->evaluate($run->refresh(), $section)['outcome']);
    }

    #[Test]
    public function a_run_signal_requires_all_affected_spoken_sections_to_be_held(): void
    {
        [$run] = $this->fixture(true);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'start_time' => 150.0,
            'end_time' => 200.0,
            'needs_manual_review' => false,
        ]);

        $signals = app(SuspectTranscriptBlockSignals::class)->for($run);

        $this->assertNotNull($signals);
        $this->assertCount(1, $signals);
        $this->assertFalse($signals[0]->held);
    }

    /** @return array{MediaProcessingLog, ServiceSection} */
    private function fixture(bool $held): array
    {
        $run = MediaProcessingLog::factory()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon,
            'start_time' => 0.0,
            'end_time' => 300.0,
            'needs_manual_review' => $held,
            'metadata' => ['review_flags' => []],
        ]);
        $run->putServiceTranscriptPath('transcripts/review.json', [], [
            (new SuspectTranscriptBlock(120.0, 180.0, SuspectTranscriptBlock::REASON_REPEATED_PHRASE, 40, 40.0))->toArray(),
        ]);

        return [$run, $section];
    }

    /**
     * @param array{start: float, end: float}|null $span
     * @return array<string, mixed>
     */
    private function evaluate(MediaProcessingLog $run, ServiceSection $section, ?array $span = null, string $truth = 'defective'): array
    {
        $source = DetectorCaseBookSource::fromArray([
            'format' => DetectorCaseBookSource::Format,
            'version' => DetectorCaseBookSource::Version,
            'cases' => [[
                'case_id' => 'transcript-containment',
                'detector_id' => 'transcript-repeated-phrase-loop',
                'subject' => ['run' => $run->id, 'section' => $section->id, 'sermon' => null, 'span' => $span],
                'truth' => $truth,
                'basis' => 'source_reviewed',
                'evidence' => 'regression fixture',
                'informed_fix' => true,
            ]],
            'aggregates' => [],
        ]);
        $book = app(FreezeDetectorCaseBook::class)->build($source);

        $this->assertSame($span, $book['cases'][0]['subject']['span']);

        return app(DetectorEvaluation::class)->evaluate($book, DetectorAcceptanceThresholds::load())['cases'][0];
    }
}
