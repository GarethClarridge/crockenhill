<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\MockServiceStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
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
    public function a_bare_run_defaults_to_the_bound_detector(): void
    {
        // The suite binds the mock detector by default, so a bare run must
        // resolve to it — never to a detector that costs money.
        $this->artisan('structure:evaluate', [
            '--manifest' => base_path('tests/Fixtures/StructureEval/manifest.json'),
            '--report' => $this->reportPath,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($this->reportPath), true);

        $this->assertSame('mock', $report['detector']);
        $this->assertCount(2, $report['services']);
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
    public function a_hard_failure_records_the_validators_message_alongside_its_code(): void
    {
        // A section claiming an OoS item id that doesn't exist for this
        // service trips the `unknown_oos_item` hard failure. The report must
        // keep the validator's message, not just the code, or a failure like
        // this cannot be diagnosed without re-running detection.
        MockServiceStructureService::useStructure(new ServiceStructure([
            new ServiceStructureSection(
                type: ServiceSectionType::Sermon,
                title: 'Sermon',
                startTime: 0.0,
                endTime: 60.0,
                confidence: 1.0,
                oosItemId: 999,
                songTitle: null,
                readingReference: null,
            ),
        ]));

        $this->artisan('structure:evaluate', [
            '--manifest' => base_path('tests/Fixtures/StructureEval/manifest.json'),
            '--detector' => 'mock',
            '--report' => $this->reportPath,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($this->reportPath), true);
        $service = $report['services'][0];

        $this->assertContains('unknown_oos_item', $service['hard_failure_codes']);
        $this->assertNotEmpty($service['hard_failure_messages']);
        $this->assertStringContainsString('999', implode(' ', $service['hard_failure_messages']));
    }

    #[Test]
    public function a_priced_openai_run_records_usage_and_cost_per_entry_and_in_the_aggregate(): void
    {
        Config::set('media-processing.analysis.openai_api_key', 'test-key');
        Config::set('media-processing.service_structure.model', 'gpt-5.6-sol');

        // The manifest below has two entries, each of which calls detect() once — the fake
        // must serve one response per call, or the second entry finds no fake response left.
        $fakeResponse = CreateResponse::fake([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'sections' => [[
                            'type' => 'welcome',
                            'title' => 'Welcome',
                            'start_time' => 0.0,
                            'end_time' => 20.0,
                            'confidence' => 0.95,
                            'oos_item_id' => 1,
                            'song_title' => null,
                            'reading_reference' => null,
                            'sermon_reference' => null,
                            'summary' => null,
                            'notes' => [],
                        ]],
                        'summary' => null,
                        'notices' => [],
                        'chapter_markers' => [],
                        'notes' => [],
                    ]),
                ],
            ]],
            'usage' => [
                'prompt_tokens' => 100_000,
                'completion_tokens' => 10_000,
                'total_tokens' => 110_000,
            ],
        ]);

        OpenAI::fake([$fakeResponse, $fakeResponse]);

        $priceSnapshotPath = storage_path('app/temp/price-snapshot-'.uniqid().'.json');
        file_put_contents($priceSnapshotPath, (string) json_encode([
            'taken_at' => '2026-08-28',
            'models' => ['gpt-5.6-sol' => ['input' => 2.0, 'output' => 8.0]],
        ]));

        try {
            $this->artisan('structure:evaluate', [
                '--manifest' => base_path('tests/Fixtures/StructureEval/manifest.json'),
                '--detector' => 'openai',
                '--price-snapshot' => $priceSnapshotPath,
                '--report' => $this->reportPath,
            ])->assertSuccessful();
        } finally {
            unlink($priceSnapshotPath);
        }

        $report = json_decode((string) file_get_contents($this->reportPath), true);

        // 100,000 input tokens * $2/M + 10,000 output tokens * $8/M = $0.28 per call.
        foreach ($report['services'] as $service) {
            $this->assertSame([
                'input_tokens' => 100_000,
                'cached_input_tokens' => 0,
                'output_tokens' => 10_000,
                'reasoning_tokens' => 0,
                'total_tokens' => 110_000,
            ], $service['usage']);
            $this->assertEqualsWithDelta(0.28, $service['cost_usd'], 0.0000001);
        }

        $usage = $report['aggregate']['usage'];
        $this->assertSame(2, $usage['calls']);
        $this->assertSame(0, $usage['usage_missing_count']);
        $this->assertSame(220_000, $usage['tokens']['total_tokens']);
        $this->assertEqualsWithDelta(0.56, $usage['total_cost_usd'], 0.0000001);
        $this->assertEqualsWithDelta(0.28, $usage['mean_cost_usd'], 0.0000001);
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
