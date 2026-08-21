<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Support\CanonicalJson;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RebaselineHashedEvidenceArtifactCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/evidence-rebaseline-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_restamps_an_artifact_written_by_the_encoder_that_dropped_the_zero_fraction(): void
    {
        $path = $this->artifactWrittenByTheOldEncoder();

        $this->artisan('evidence:rebaseline-hash', [
            '--path' => [$path],
            '--key' => 'evidence_hash',
            '--apply' => true,
        ])->assertSuccessful();

        $restamped = json_decode((string) file_get_contents($path), true);
        $recorded = $restamped['evidence_hash'];
        unset($restamped['evidence_hash']);

        $this->assertSame($recorded, CanonicalJson::hash($restamped));
    }

    #[Test]
    public function it_reports_without_writing_unless_apply_is_passed(): void
    {
        $path = $this->artifactWrittenByTheOldEncoder();
        $before = (string) file_get_contents($path);

        $this->artisan('evidence:rebaseline-hash', [
            '--path' => [$path],
            '--key' => 'evidence_hash',
        ])->assertSuccessful();

        $this->assertSame($before, (string) file_get_contents($path));
    }

    #[Test]
    public function an_artifact_that_already_verifies_is_left_untouched(): void
    {
        // The command re-derives a hash from whatever the file holds, so it must never rewrite one
        // that still reproduces itself: doing so would quietly re-bless content on every run.
        $artifact = ['format' => 'test', 'metrics' => ['rate' => 1.0, 'count' => 3]];
        $artifact['evidence_hash'] = CanonicalJson::hash($artifact);
        $path = $this->root.'/verifying.json';
        file_put_contents($path, CanonicalJson::encodeReadable($artifact).PHP_EOL);
        $before = (string) file_get_contents($path);

        $this->artisan('evidence:rebaseline-hash', [
            '--path' => [$path],
            '--key' => 'evidence_hash',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame($before, (string) file_get_contents($path));
    }

    #[Test]
    public function it_fails_when_the_named_key_is_not_present(): void
    {
        $path = $this->root.'/no-key.json';
        file_put_contents($path, CanonicalJson::encodeReadable(['format' => 'test']).PHP_EOL);

        $this->artisan('evidence:rebaseline-hash', [
            '--path' => [$path],
            '--key' => 'evidence_hash',
            '--apply' => true,
        ])->assertFailed();
    }

    /**
     * An artifact hashed in memory and then persisted by the pre-fix encoder, which omitted
     * `JSON_PRESERVE_ZERO_FRACTION` and so wrote the integral float `1.0` as `1`.
     */
    private function artifactWrittenByTheOldEncoder(): string
    {
        $artifact = ['format' => 'test', 'metrics' => ['rate' => 1.0, 'count' => 3]];
        $artifact['evidence_hash'] = CanonicalJson::hash($artifact);

        $path = $this->root.'/drifted.json';
        file_put_contents($path, json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

        $reread = json_decode((string) file_get_contents($path), true);
        $recorded = $reread['evidence_hash'];
        unset($reread['evidence_hash']);
        $this->assertNotSame($recorded, CanonicalJson::hash($reread), 'The fixture must reproduce the encoder defect.');

        return $path;
    }
}
