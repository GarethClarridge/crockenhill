<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EvaluateSavedStructureDrawsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/saved-draws-'.getmypid());
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function it_composes_disjoint_mixed_model_sequences_and_scores_them_without_writing(): void
    {
        $plain = [$this->section('welcome', 0, 300), $this->section('sermon', 300, 2000)];
        $invented = [$this->section('welcome', 0, 120), $this->section('short_talk', 120, 300), $this->section('sermon', 300, 2000)];

        $this->draw('p2-g56-luna-d1', 'gpt-5.6-luna', ['100' => ['sections' => $plain]]);
        $this->draw('p2-g56-luna-d2', 'gpt-5.6-luna', ['100' => ['sections' => $plain]]);
        $this->draw('p2-g56-luna-d3', 'gpt-5.6-luna', ['100' => ['sections' => $plain]]);
        $this->draw('p2-g6-luna-d1', 'gpt-6-luna', ['100' => ['sections' => $invented]]);
        $this->draw('p2-g6-luna-d2', 'gpt-6-luna', ['100' => ['sections' => $plain, 'pipeline_steps' => ['reading_recheck_adopted']]]);
        $truth = $this->directory.'/truth.json';
        File::put($truth, json_encode(['runs' => ['100' => [
            ['type' => 'sermon', 'start' => 300, 'end' => 2000, 'basis' => 'operator'],
        ]]]));
        $report = $this->directory.'/report.json';
        MediaProcessingLog::factory()->create();
        $before = MediaProcessingLog::query()->get()->toArray();

        $this->artisan('structure:ensemble-evaluate-saved-draws', [
            'directory' => $this->directory,
            'truth' => $truth,
            '--report' => $report,
        ])->assertSuccessful();

        $result = json_decode(File::get($report), true);
        $sequence = $result['sets']['p2']['sequences'][0];
        $run = $sequence['runs']['100'];

        $this->assertCount(1, $result['sets']['p2']['sequences']);
        $this->assertSame(['p2-g56-luna-d1', 'p2-g56-luna-d2', 'p2-g6-luna-d1', 'p2-g6-luna-d2'], $sequence['draws']);
        $this->assertSame(3, $run['valid_votes']);
        $this->assertSame(['not_first_attempt'], array_values(array_unique(array_column(
            array_filter($run['slots'], static fn (array $slot): bool => $slot['status'] !== 'valid'), 'status'))));
        $this->assertTrue($run['degraded']);
        $this->assertSame(0, $run['talk_count']['detected']);
        $this->assertFalse($run['talk_count']['error']);
        $this->assertContains('short_talk', array_keys($run['disputes_by_type']));
        $this->assertSame(1, $result['sets']['p2']['summary']['services_flagged']);
        $this->assertSame(0, $result['sets']['p2']['summary']['unflagged_talk_count_errors']);
        $this->assertSame($before, MediaProcessingLog::query()->get()->toArray());
    }

    #[Test]
    public function single_model_sets_are_labelled_as_such(): void
    {
        $plain = [$this->section('sermon', 0, 2000)];

        foreach (range(1, 4) as $number) {
            $this->draw("p5order-g56-luna-d{$number}", 'gpt-5.6-luna', ['200' => ['sections' => $plain]]);
        }

        $truth = $this->directory.'/truth.json';
        File::put($truth, json_encode(['runs' => []]));
        $report = $this->directory.'/report.json';

        $this->artisan('structure:ensemble-evaluate-saved-draws', [
            'directory' => $this->directory,
            'truth' => $truth,
            '--report' => $report,
        ])->assertSuccessful();

        $result = json_decode(File::get($report), true);

        $this->assertSame('gpt-5.6-luna ×4 (not the configured mix)', $result['sets']['p5order']['composition']);
        $this->assertNull($result['sets']['p5order']['sequences'][0]['runs']['200']['talk_count']);
        $this->assertSame(0, $result['sets']['p5order']['summary']['services_flagged']);
    }

    /** @return array<string, mixed> */
    private function section(string $type, float $start, float $end): array
    {
        return ['type' => $type, 'start' => $start, 'end' => $end, 'title' => null, 'flags' => []];
    }

    /** @param  array<string, array<string, mixed>>  $runs */
    private function draw(string $arm, string $model, array $runs): void
    {
        File::put("{$this->directory}/{$arm}.json", json_encode([
            'arm' => $arm,
            'model' => $model,
            'runs' => array_map(static fn (array $run): array => [
                'hard_failures' => [],
                'pipeline_steps' => [],
                ...$run,
            ], $runs),
        ]));
    }
}
