<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructureShadowReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reportPath = storage_path('app/temp/shadow-report-'.uniqid().'.json');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->reportPath)) {
            unlink($this->reportPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_aggregates_shadow_runs_including_errors(): void
    {
        $cleanRun = $this->makeShadowRun([
            'generated_at' => '2026-06-07T11:45:00+01:00',
            'model' => 'gpt-5',
            'passed_validation' => true,
            'hard_failures' => [],
            'unmatched_oos_item_ids' => [],
            'sections' => [
                ['section_type' => 'sermon', 'needs_manual_review' => false],
                ['section_type' => 'song', 'needs_manual_review' => true],
            ],
            'diff' => [
                'type_sequence_match' => true,
                'sermon' => ['start_delta' => -12.0, 'end_delta' => 8.0],
                'oos_anchoring' => ['agreements' => 4, 'disagreements' => 1],
            ],
        ]);

        $failedRun = $this->makeShadowRun([
            'generated_at' => '2026-06-14T11:45:00+01:00',
            'passed_validation' => false,
            'hard_failures' => [['code' => 'multiple_sermons', 'message' => 'Two sermons detected.']],
            'sections' => [],
            'diff' => [
                'type_sequence_match' => false,
                'sermon' => ['start_delta' => -200.0, 'end_delta' => 90.0],
                'oos_anchoring' => null,
            ],
        ]);

        $erroredRun = $this->makeShadowRun([
            'generated_at' => '2026-06-21T11:45:00+01:00',
            'error' => 'Full-service transcript artifact missing: temp/foo.json',
        ]);

        // A run with no shadow metadata must be excluded entirely.
        MediaProcessingLog::factory()->livestream()->completed()->create();

        $this->artisan('structure:shadow-report', ['--report' => $this->reportPath])
            ->assertSuccessful();

        $report = json_decode((string) file_get_contents($this->reportPath), true);

        $this->assertCount(3, $report['runs']);

        $aggregate = $report['aggregate'];
        $this->assertSame(3, $aggregate['run_count']);
        $this->assertSame(1, $aggregate['error_count']);
        $this->assertEquals(0.5, $aggregate['passed_validation_rate']);
        $this->assertEquals(0.5, $aggregate['type_sequence_match_rate']);
        $this->assertSame(2, $aggregate['sermon']['compared']);
        $this->assertEquals(0.5, $aggregate['sermon']['within_15s_rate']);
        $this->assertEquals(0.5, $aggregate['sermon']['within_30s_rate']);
        $this->assertEquals(106.0, $aggregate['sermon']['mean_abs_start_delta']);
        $this->assertEquals(49.0, $aggregate['sermon']['mean_abs_end_delta']);
        $this->assertSame(4, $aggregate['oos_anchoring']['agreements']);
        $this->assertSame(1, $aggregate['oos_anchoring']['disagreements']);
        $this->assertEquals(0.8, $aggregate['oos_anchoring']['agreement_rate']);
        // The clean run has a flagged section; the failed run failed validation.
        $this->assertSame(2, $aggregate['would_have_flagged_count']);
        $this->assertSame(['multiple_sermons' => 1], $aggregate['hard_failure_codes']);

        $this->assertSame($cleanRun->processing_id, $report['runs'][0]['processing_id']);
        $this->assertFalse($report['runs'][1]['passed_validation']);
        $this->assertSame($failedRun->processing_id, $report['runs'][1]['processing_id']);
        $this->assertSame($erroredRun->processing_id, $report['runs'][2]['processing_id']);
        $this->assertStringContainsString('transcript artifact missing', $report['runs'][2]['error']);
    }

    #[Test]
    public function it_surfaces_baseline_provenance_per_run_and_in_the_aggregate(): void
    {
        // A pre-flip run diffed against heuristic sections…
        $this->makeShadowRun([
            'generated_at' => '2026-06-07T11:45:00+01:00',
            'passed_validation' => true,
            'hard_failures' => [],
            'sections' => [],
            'diff' => [
                'baseline' => ['classification_modes' => ['audio_only', 'ai_transcript']],
                'type_sequence_match' => true,
                'sermon' => null,
                'oos_anchoring' => null,
            ],
        ]);

        // …and a post-flip model trial diffed against primary LLM sections.
        $this->makeShadowRun([
            'generated_at' => '2026-06-14T11:45:00+01:00',
            'passed_validation' => true,
            'hard_failures' => [],
            'sections' => [],
            'diff' => [
                'baseline' => ['classification_modes' => ['llm_structure']],
                'type_sequence_match' => true,
                'sermon' => null,
                'oos_anchoring' => null,
            ],
        ]);

        $this->artisan('structure:shadow-report', ['--report' => $this->reportPath])
            ->assertSuccessful();

        $report = json_decode((string) file_get_contents($this->reportPath), true);

        $this->assertSame(['classification_modes' => ['audio_only', 'ai_transcript']], $report['runs'][0]['baseline']);
        $this->assertSame(['classification_modes' => ['llm_structure']], $report['runs'][1]['baseline']);
        $this->assertSame(
            ['ai_transcript+audio_only' => 1, 'llm_structure' => 1],
            $report['aggregate']['baseline_counts'],
            'Mixed baselines must be visible so incomparable deltas are not silently combined.'
        );
    }

    #[Test]
    public function it_filters_by_processing_id(): void
    {
        $wanted = $this->makeShadowRun([
            'generated_at' => '2026-06-07T11:45:00+01:00',
            'passed_validation' => true,
            'hard_failures' => [],
            'sections' => [],
            'diff' => ['type_sequence_match' => true, 'sermon' => null, 'oos_anchoring' => null],
        ]);
        $this->makeShadowRun([
            'generated_at' => '2026-06-14T11:45:00+01:00',
            'error' => 'unwanted run',
        ]);

        $this->artisan('structure:shadow-report', [
            '--processing-id' => [$wanted->processing_id],
            '--report' => $this->reportPath,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($this->reportPath), true);

        $this->assertCount(1, $report['runs']);
        $this->assertSame($wanted->processing_id, $report['runs'][0]['processing_id']);
    }

    #[Test]
    public function it_reports_gracefully_when_no_shadow_runs_exist(): void
    {
        $this->artisan('structure:shadow-report')
            ->expectsOutputToContain('No shadow-mode runs found')
            ->assertSuccessful();
    }

    /**
     * Runs are ordered by created_at in the report, so each fixture pins its
     * creation time to the shadow generated_at (falling back to now).
     *
     * @param  array<string, mixed>  $shadow
     */
    private function makeShadowRun(array $shadow): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_metadata' => ['service_structure_shadow' => $shadow],
            'created_at' => $shadow['generated_at'] ?? now(),
        ]);
    }
}
