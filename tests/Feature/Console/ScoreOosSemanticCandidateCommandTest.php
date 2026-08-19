<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Email\OosSemanticCorrectnessScorer;
use App\Services\Email\RunOosSemanticSafetyFixtures;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScoreOosSemanticCandidateCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/semantic-score-command-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_create_once_private_scoring_artifact_and_lists_every_gate(): void
    {
        $paths = $this->inputs();
        $this->fakeFixtures();
        $this->fakeScorer([
            'inference' => ['label' => 'scored', 'refusals' => []],
            'verdict' => 'incomplete',
            'gates' => [
                ['gate' => 1, 'name' => 'source_line_identity', 'status' => 'pass'],
                ['gate' => 9, 'name' => 'weekly_and_historic_entry_point_parity', 'status' => 'not_scored'],
            ],
        ]);

        $this->artisan('oos:score-semantic-candidate', $paths)
            ->expectsOutputToContain('Verdict: incomplete')
            ->expectsOutputToContain('source_line_identity')
            ->expectsOutputToContain('NOT_SCORED')
            ->assertSuccessful();

        $this->assertFileExists($paths['--output']);
        $this->assertSame(0600, fileperms($paths['--output']) & 0777);

        $this->artisan('oos:score-semantic-candidate', $paths)
            ->expectsOutputToContain('Refusing to overwrite')
            ->assertFailed();
    }

    #[Test]
    public function a_refused_score_reports_every_refusal_and_fails_the_command(): void
    {
        $paths = $this->inputs();
        $this->fakeFixtures();
        $this->fakeScorer([
            'inference' => ['label' => 'refused', 'refusals' => ['Truth is not fully adjudicated.']],
            'verdict' => null,
            'gates' => null,
        ]);

        $this->artisan('oos:score-semantic-candidate', $paths)
            ->expectsOutputToContain('No verdict was produced')
            ->expectsOutputToContain('Truth is not fully adjudicated.')
            ->assertFailed();
    }

    #[Test]
    public function it_refuses_a_relative_output_path(): void
    {
        $paths = $this->inputs();
        $paths['--output'] = 'storage/scratch/score.json';

        $this->artisan('oos:score-semantic-candidate', $paths)
            ->expectsOutputToContain('--output must be an absolute path')
            ->assertFailed();
    }

    /** @return array<string, string> */
    private function inputs(): array
    {
        foreach (['corpus', 'candidate', 'baseline'] as $name) {
            File::put("{$this->root}/{$name}.json", '{}');
        }

        return [
            '--corpus' => "{$this->root}/corpus.json",
            '--candidate' => "{$this->root}/candidate.json",
            '--baseline-stability' => "{$this->root}/baseline.json",
            '--output' => "{$this->root}/score.json",
        ];
    }

    private function fakeFixtures(): void
    {
        $fixtures = Mockery::mock(RunOosSemanticSafetyFixtures::class);
        $fixtures->shouldReceive('run')->andReturn(['summary' => ['unsatisfied' => 0]]);
        $this->instance(RunOosSemanticSafetyFixtures::class, $fixtures);
    }

    /** @param array<string, mixed> $report */
    private function fakeScorer(array $report): void
    {
        $scorer = Mockery::mock(OosSemanticCorrectnessScorer::class);
        $scorer->shouldReceive('score')->andReturn($report);
        $this->instance(OosSemanticCorrectnessScorer::class, $scorer);
    }
}
