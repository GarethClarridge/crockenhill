<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OosSemanticCandidateArtifactFixture;
use Tests\TestCase;

class RecompileOosSemanticCandidateEvidenceCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/oos-recompile-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_recompiled_artifact_privately(): void
    {
        [$corpus, $candidate] = $this->inputs();
        $output = $this->root.'/recompiled.json';

        $this->artisan('oos:recompile-semantic-candidate-evidence', [
            '--corpus' => $corpus,
            '--candidate' => $candidate,
            '--output' => $output,
        ])->assertSuccessful();

        $artifact = json_decode((string) file_get_contents($output), true);

        $this->assertSame('0600', substr(sprintf('%o', fileperms($output)), -4));
        $this->assertSame('partial', $artifact['results'][0]['extraction']['services'][0]['content_scope']);
        $this->assertSame(['sample'], $artifact['recompilation']['sources_with_changed_compilation']);

        $withoutHash = $artifact;
        unset($withoutHash['evidence_hash']);
        $this->assertSame($artifact['evidence_hash'], CanonicalJson::hash($withoutHash));
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_artifact(): void
    {
        [$corpus, $candidate] = $this->inputs();
        $output = $this->root.'/taken.json';
        File::put($output, '{}');

        $this->artisan('oos:recompile-semantic-candidate-evidence', [
            '--corpus' => $corpus,
            '--candidate' => $candidate,
            '--output' => $output,
        ])->assertFailed();

        $this->assertSame('{}', (string) file_get_contents($output));
    }

    #[Test]
    public function it_fails_without_writing_anything_when_the_candidate_has_drifted(): void
    {
        [$corpus, $candidate] = $this->inputs(static function (array $artifact): array {
            $artifact['usage']['total_tokens'] = 999999;

            return $artifact;
        });
        $output = $this->root.'/never-written.json';

        $this->artisan('oos:recompile-semantic-candidate-evidence', [
            '--corpus' => $corpus,
            '--candidate' => $candidate,
            '--output' => $output,
        ])->assertFailed();

        $this->assertFileDoesNotExist($output);
    }

    /**
     * @param  null|callable(array<string, mixed>): array<string, mixed>  $driftAfterHashing
     * @return array{0:string,1:string}
     */
    private function inputs(?callable $driftAfterHashing = null): array
    {
        $fixtures = new OosSemanticCandidateArtifactFixture;
        $candidate = $fixtures->candidate();

        if ($driftAfterHashing !== null) {
            $candidate = $driftAfterHashing($candidate);
        }

        $corpusPath = $this->root.'/corpus.json';
        $candidatePath = $this->root.'/candidate.json';
        File::put($corpusPath, CanonicalJson::encodeReadable($fixtures->corpus()));
        File::put($candidatePath, CanonicalJson::encodeReadable($candidate));

        return [$corpusPath, $candidatePath];
    }
}
