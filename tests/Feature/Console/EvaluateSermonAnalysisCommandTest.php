<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EvaluateSermonAnalysisCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/sermon-analysis-evaluation-'.bin2hex(random_bytes(6));
        mkdir($this->root);

        config([
            'media-processing.analysis.service' => 'openai',
            'media-processing.analysis.openai_api_key' => 'test-key',
            'media-processing.analysis.model' => 'gpt-5.6-terra',
            'media-processing.analysis.reasoning_effort' => 'low',
            'openai.api_key' => 'test-key',
            'openai.evaluation_arm' => 'sermon-analysis-test',
            'openai.service_tier' => 'flex',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->root}/*") ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->root)) {
            rmdir($this->root);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_bound_report_without_mutating_sermons(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Curated title',
            'summary' => 'Curated summary',
            'reference' => 'Romans 8:1',
        ]);
        $transcript = $this->transcript();
        $manifest = $this->writeManifest([
            [
                'label' => '2026-07-26-morning',
                'file' => 'sermon.txt',
                'sha256' => hash('sha256', $transcript),
            ],
        ]);
        $priceSnapshot = $this->writePriceSnapshot();
        $output = "{$this->root}/report.json";
        $rawOutput = json_encode([
            'title' => 'The faithful promise',
            'series' => null,
            'reference' => 'Romans 8:1-4',
            'points' => ['God keeps his promise'],
            'summary' => 'God assures us that there is no condemnation for those who are in Christ Jesus.',
        ], JSON_THROW_ON_ERROR);

        file_put_contents("{$this->root}/sermon.txt", $transcript);
        OpenAI::fake([$this->response($rawOutput)]);

        $this->artisan('sermons:evaluate-analysis', [
            '--manifest' => $manifest,
            '--arm' => 'sermon-analysis-test',
            '--price-snapshot' => $priceSnapshot,
            '--output' => $output,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        $sermon->refresh();

        $this->assertSame('crockenhill-sermon-analysis-evaluation', $report['format']);
        $this->assertSame(hash_file('sha256', $manifest), $report['manifest_sha256']);
        $this->assertSame(hash('sha256', $transcript), $report['results'][0]['input_sha256']);
        $this->assertSame($rawOutput, $report['results'][0]['response']['raw_output']);
        $this->assertSame('gpt-5.6-terra-2026-08-01', $report['results'][0]['response']['response_model']);
        $this->assertSame(100, $report['summary']['usage']['input_tokens']);
        $this->assertSame(10, $report['summary']['usage']['cached_input_tokens']);
        $this->assertSame(50, $report['summary']['usage']['output_tokens']);
        $this->assertSame(20, $report['summary']['usage']['reasoning_tokens']);
        $this->assertSame(0, $report['retries']);
        $this->assertSame(0, $report['rechecks']);
        $this->assertSame('passed', $report['results'][0]['validation']['status']);
        $this->assertArrayNotHasKey('transcript', $report['results'][0]['validation']['normalised_output']);
        $this->assertSame('Curated title', $sermon->title);
        $this->assertSame('Curated summary', $sermon->summary);
        $this->assertSame(1, Sermon::query()->count());
        $this->assertSame(0600, fileperms($output) & 0777);
    }

    #[Test]
    public function it_retains_truncation_and_failure_details_in_the_report(): void
    {
        $transcript = $this->transcript();
        $manifest = $this->writeManifest([
            ['label' => 'truncated', 'file' => 'sermon.txt'],
        ]);
        $priceSnapshot = $this->writePriceSnapshot();
        $output = "{$this->root}/truncated-report.json";

        file_put_contents("{$this->root}/sermon.txt", $transcript);
        OpenAI::fake([$this->response('{"title":"unfinished', finishReason: 'length')]);

        $this->artisan('sermons:evaluate-analysis', [
            '--manifest' => $manifest,
            '--arm' => 'sermon-analysis-test',
            '--price-snapshot' => $priceSnapshot,
            '--output' => $output,
        ])->assertFailed();

        $report = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('failed', $report['results'][0]['status']);
        $this->assertTrue($report['results'][0]['response']['truncated']);
        $this->assertSame('{"title":"unfinished', $report['results'][0]['response']['raw_output']);
        $this->assertStringContainsString('Failed to parse JSON', $report['results'][0]['failure']['message']);
        $this->assertSame(1, $report['summary']['failure_count']);
    }

    #[Test]
    public function it_refuses_a_changed_banked_transcript_before_calling_openai(): void
    {
        $transcript = $this->transcript();
        $manifest = $this->writeManifest([
            [
                'label' => 'changed-input',
                'file' => 'sermon.txt',
                'sha256' => hash('sha256', 'the original bytes'),
            ],
        ]);
        $priceSnapshot = $this->writePriceSnapshot();
        $output = "{$this->root}/refused-report.json";

        file_put_contents("{$this->root}/sermon.txt", $transcript);
        OpenAI::fake();

        $this->artisan('sermons:evaluate-analysis', [
            '--manifest' => $manifest,
            '--arm' => 'sermon-analysis-test',
            '--price-snapshot' => $priceSnapshot,
            '--output' => $output,
        ])
            ->expectsOutputToContain('Transcript hash mismatch')
            ->assertFailed();

        OpenAI::assertNothingSent();
        $this->assertFileDoesNotExist($output);
    }

    /** @param list<array<string, mixed>> $entries */
    private function writeManifest(array $entries): string
    {
        file_put_contents("{$this->root}/manifest.json", json_encode([
            'format' => 'crockenhill-sermon-analysis-evaluation',
            'version' => 1,
            'transcripts' => $entries,
        ], JSON_THROW_ON_ERROR));

        return "{$this->root}/manifest.json";
    }

    private function writePriceSnapshot(): string
    {
        file_put_contents("{$this->root}/prices.json", json_encode([
            'taken_at' => '2026-08-26',
            'models' => [
                'gpt-5.6-terra' => [
                    'input' => 2.0,
                    'cached_input' => 0.2,
                    'output' => 12.0,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return "{$this->root}/prices.json";
    }

    private function transcript(): string
    {
        return str_repeat(
            'The preacher explains the good news of Jesus Christ and calls us to trust God faithfully. ',
            8,
        );
    }

    private function response(string $content, string $finishReason = 'stop'): CreateResponse
    {
        return CreateResponse::fake([
            'id' => 'chatcmpl-evaluation',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-5.6-terra-2026-08-01',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => $finishReason,
            ]],
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
                'prompt_tokens_details' => ['cached_tokens' => 10],
                'completion_tokens_details' => ['reasoning_tokens' => 20],
            ],
        ]);
    }
}
