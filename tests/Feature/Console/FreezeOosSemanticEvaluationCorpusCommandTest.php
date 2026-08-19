<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\OosCurationPlan;
use App\Services\Email\FreezeOosSemanticEvaluationCorpus;
use App\Services\Email\OosCurationEntryFactory;
use App\Services\Email\OosCurationManifest;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FreezeOosSemanticEvaluationCorpusCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/semantic-corpus-command-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_create_once_private_artifact(): void
    {
        $inputs = [];

        foreach (['manifest', 'truth', 'legacy', 'stability'] as $name) {
            $inputs[$name] = "{$this->root}/{$name}.json";
            File::put($inputs[$name], '{}');
        }

        $plan = new OosCurationPlan(str_repeat('a', 64), str_repeat('b', 64), [], [], 'test');
        $manifest = Mockery::mock(OosCurationManifest::class);
        $manifest->shouldReceive('plan')->once()->andReturn($plan);
        $manifest->shouldReceive('snapshots')->once()->with(Mockery::any(), Mockery::any(), $plan)->andReturn([]);
        $factory = Mockery::mock(OosCurationEntryFactory::class);
        $factory->shouldReceive('entries')->once()->with($plan, [])->andReturn([]);
        $freezer = Mockery::mock(FreezeOosSemanticEvaluationCorpus::class);
        $freezer->shouldReceive('build')->once()->andReturn([
            'format' => FreezeOosSemanticEvaluationCorpus::Format,
            'completeness' => ['source_count' => 1],
            'corpus_hash' => str_repeat('c', 64),
        ]);
        $this->instance(OosCurationManifest::class, $manifest);
        $this->instance(OosCurationEntryFactory::class, $factory);
        $this->instance(FreezeOosSemanticEvaluationCorpus::class, $freezer);
        $output = "{$this->root}/corpus.json";

        $this->artisan('oos:freeze-semantic-evaluation-corpus', [
            '--manifest' => $inputs['manifest'],
            '--verbatim' => $this->root,
            '--formatted' => $this->root.'/formatted',
            '--item-truth' => $inputs['truth'],
            '--legacy-projection' => $inputs['legacy'],
            '--stability-diagnostic' => $inputs['stability'],
            '--output' => $output,
        ])->assertSuccessful();

        $this->assertFileExists($output);
        $this->assertSame(0600, fileperms($output) & 0777);

        $this->artisan('oos:freeze-semantic-evaluation-corpus', [
            '--manifest' => $inputs['manifest'],
            '--verbatim' => $this->root,
            '--formatted' => $this->root.'/formatted',
            '--item-truth' => $inputs['truth'],
            '--legacy-projection' => $inputs['legacy'],
            '--stability-diagnostic' => $inputs['stability'],
            '--output' => $output,
        ])->expectsOutputToContain('Refusing to overwrite')->assertFailed();
    }

    #[Test]
    public function it_requires_an_absolute_private_output_path(): void
    {
        $this->artisan('oos:freeze-semantic-evaluation-corpus', [
            '--manifest' => 'manifest.json',
            '--item-truth' => 'truth.json',
            '--legacy-projection' => 'legacy.json',
            '--stability-diagnostic' => 'stability.json',
            '--output' => 'corpus.json',
        ])->expectsOutputToContain('--output must be an absolute path')->assertFailed();
    }
}
