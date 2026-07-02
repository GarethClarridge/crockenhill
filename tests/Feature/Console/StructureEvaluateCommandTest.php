<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\ChurchService\Structure\MockServiceStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StructureEvaluateCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reportPath = storage_path('app/temp/structure-eval-report-'.uniqid().'.json');
    }

    protected function tearDown(): void
    {
        MockServiceStructureService::useStructure(null);

        if (file_exists($this->reportPath)) {
            unlink($this->reportPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_evaluates_the_fixture_manifest_against_the_mock_detector(): void
    {
        $this->artisan('structure:evaluate', [
            '--manifest' => base_path('tests/Fixtures/StructureEval/manifest.json'),
            '--detector' => 'mock',
            '--report' => $this->reportPath,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($this->reportPath), true);

        $this->assertSame('mock', $report['detector']);
        $this->assertCount(2, $report['services']);

        // The accurate entry: the mock detector reproduces the expectations exactly.
        $accurate = $report['services'][0];
        $this->assertNull($accurate['error']);
        $this->assertSame([], $accurate['hard_failure_codes']);
        $this->assertEquals(0.0, $accurate['sermon']['start_delta']);
        $this->assertEquals(0.0, $accurate['sermon']['end_delta']);
        $this->assertTrue($accurate['sermon']['within_15s']);
        $this->assertEquals(1.0, $accurate['section_types']['accuracy']);
        $this->assertTrue($accurate['section_types']['ordering_match']);
        $this->assertEquals(1.0, $accurate['oos_anchoring']['precision']);
        $this->assertEquals(1.0, $accurate['oos_anchoring']['recall']);
        $this->assertEquals(1.0, $accurate['song_titles']['rate']);
        $this->assertNull($accurate['reading_references']['rate'], 'No readings expected means no rate.');

        // The deliberately-wrong entry must produce non-zero deltas and misses.
        $wrong = $report['services'][1];
        $this->assertNull($wrong['error']);
        $this->assertEquals(-270.0, $wrong['sermon']['start_delta']);
        $this->assertEquals(100.0, $wrong['sermon']['end_delta']);
        $this->assertFalse($wrong['sermon']['within_30s']);
        $this->assertEquals(0.5, $wrong['section_types']['accuracy']);
        $this->assertFalse($wrong['section_types']['ordering_match']);
        $this->assertEquals(0.0, $wrong['oos_anchoring']['precision']);
        $this->assertEquals(0.0, $wrong['oos_anchoring']['recall']);
        $this->assertEquals(0.0, $wrong['song_titles']['rate']);
        $this->assertEquals(0.0, $wrong['reading_references']['rate']);
    }

    #[Test]
    public function the_aggregate_arithmetic_combines_both_entries(): void
    {
        $this->artisan('structure:evaluate', [
            '--manifest' => base_path('tests/Fixtures/StructureEval/manifest.json'),
            '--detector' => 'mock',
            '--report' => $this->reportPath,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($this->reportPath), true);
        $aggregate = $report['aggregate'];

        $this->assertSame(2, $aggregate['service_count']);
        $this->assertSame(0, $aggregate['error_count']);
        $this->assertEquals(0.5, $aggregate['sermon']['within_15s_rate']);
        $this->assertEquals(0.5, $aggregate['sermon']['within_30s_rate']);
        $this->assertEquals(135.0, $aggregate['sermon']['mean_abs_start_delta']);
        $this->assertEquals(50.0, $aggregate['sermon']['mean_abs_end_delta']);
        $this->assertEquals(0.75, $aggregate['section_type_accuracy']);
        $this->assertEquals(0.5, $aggregate['song_title_match_rate']);
        $this->assertEquals(0.0, $aggregate['reading_reference_accuracy']);
        $this->assertEquals(0.0, $aggregate['hard_validation_failure_rate']);
        $this->assertIsNumeric($aggregate['mean_latency_seconds']);
    }

    #[Test]
    public function it_fails_cleanly_when_nothing_is_given_to_evaluate(): void
    {
        $this->artisan('structure:evaluate', ['--detector' => 'mock'])
            ->expectsOutputToContain('Nothing to evaluate')
            ->assertFailed();
    }

    #[Test]
    public function an_entry_with_a_missing_transcript_reports_an_error_without_aborting(): void
    {
        $manifestPath = storage_path('app/temp/broken-manifest-'.uniqid().'.json');
        file_put_contents($manifestPath, (string) json_encode([
            'services' => [
                ['label' => 'broken entry', 'transcript_file' => 'does-not-exist.json'],
            ],
        ]));

        try {
            $this->artisan('structure:evaluate', [
                '--manifest' => $manifestPath,
                '--detector' => 'mock',
                '--report' => $this->reportPath,
            ])->assertSuccessful();

            $report = json_decode((string) file_get_contents($this->reportPath), true);

            $this->assertStringContainsString('not found', $report['services'][0]['error']);
            $this->assertSame(1, $report['aggregate']['error_count']);
        } finally {
            unlink($manifestPath);
        }
    }
}
