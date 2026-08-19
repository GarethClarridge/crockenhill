<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Email\OosSemanticCandidateEvidenceRunner;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunOosSemanticCandidateEvidenceCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/semantic-evidence-command-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_requires_explicit_paid_approval_before_resolving_the_runner(): void
    {
        $runner = Mockery::mock(OosSemanticCandidateEvidenceRunner::class);
        $runner->shouldNotReceive('run');
        $this->instance(OosSemanticCandidateEvidenceRunner::class, $runner);

        $this->artisan('oos:run-semantic-candidate-evidence')
            ->expectsOutputToContain('Paid model approval must be confirmed')
            ->assertFailed();
    }

    #[Test]
    public function it_writes_create_once_private_raw_evidence_without_calling_it_a_verdict(): void
    {
        $corpus = "{$this->root}/corpus.json";
        $prices = "{$this->root}/prices.json";
        $output = "{$this->root}/evidence.json";
        File::put($corpus, '{}');
        File::put($prices, '{}');
        $runner = Mockery::mock(OosSemanticCandidateEvidenceRunner::class);
        $runner->shouldReceive('run')->once()->andReturn([
            'candidate' => ['returned_model' => 'gpt-5.6-terra-test'],
            'usage' => ['calls' => 1, 'total_tokens' => 100],
        ]);
        $this->instance(OosSemanticCandidateEvidenceRunner::class, $runner);

        $this->artisan('oos:run-semantic-candidate-evidence', [
            '--corpus' => $corpus,
            '--price-snapshot' => $prices,
            '--output' => $output,
            '--paid-model-approved' => true,
        ])->expectsOutputToContain('raw evidence, not a passing verdict')->assertSuccessful();

        $this->assertFileExists($output);
        $this->assertSame(0600, fileperms($output) & 0777);

        $this->artisan('oos:run-semantic-candidate-evidence', [
            '--corpus' => $corpus,
            '--price-snapshot' => $prices,
            '--output' => $output,
            '--paid-model-approved' => true,
        ])->expectsOutputToContain('Refusing to overwrite')->assertFailed();
    }
}
