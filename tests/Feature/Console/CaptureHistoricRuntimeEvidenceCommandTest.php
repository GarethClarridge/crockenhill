<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Import\HistoricImportRuntimeEvidenceCollector;
use App\Services\Import\HistoricImportRuntimePreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The producer's value is in what it refuses. A collector that fills gaps with defaults would
 * satisfy {@see HistoricImportRuntimePreflight} while attesting to things nobody observed, which is
 * exactly the failure the missing producer was hiding.
 */
class CaptureHistoricRuntimeEvidenceCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/historic-runtime-'.bin2hex(random_bytes(6));
        mkdir($this->root);
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

    /**
     * The positive case, and the one that matters: what this produces must satisfy the validator
     * that had no producer. Asserting against the real preflight rather than a fixture means the
     * two cannot drift apart.
     */
    #[Test]
    public function it_produces_evidence_the_real_preflight_accepts(): void
    {
        Config::set('media-processing.storage.sermon_disk', 'probe_disk');
        Config::set('filesystems.disks.probe_disk', [
            'driver' => 's3',
            'endpoint' => 'https://secure.example.com',
        ]);
        Config::set('openai.api_key', 'test-key');
        Config::set('media-processing.analysis.service', 'openai');
        Config::set('media-processing.service_structure.detector', 'openai');
        Config::set('media-processing.transcription.service', 'local');

        Http::fake([
            '*/models' => Http::response(['data' => []], 200, ['Date' => gmdate('D, d M Y H:i:s').' GMT']),
            '*' => Http::response('', 200),
        ]);

        $evidence = app(HistoricImportRuntimeEvidenceCollector::class)->collect($this->attested());

        $fingerprint = app(HistoricImportRuntimePreflight::class)->fingerprint($evidence);

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $fingerprint);
        $this->assertSame('crockenhill.historic-runtime.v1', $evidence['format']);
        $this->assertTrue($evidence['storage']['encryption_at_rest_verified']);
        $this->assertSame(
            ['ffmpeg', 'ffprobe'],
            array_keys($evidence['binaries']),
            'The contract requires exactly these two binaries.',
        );
        $this->assertTrue($evidence['providers']['sermon_analysis']['connectivity_verified']);
    }

    #[Test]
    public function it_refuses_a_local_sermon_disk_because_encryption_at_rest_cannot_be_observed(): void
    {
        Config::set('media-processing.storage.sermon_disk', 'historic_staging');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot evidence encryption at rest');

        app(HistoricImportRuntimeEvidenceCollector::class)->collect($this->attested());
    }

    #[Test]
    public function it_refuses_an_object_store_reached_without_tls(): void
    {
        Config::set('media-processing.storage.sermon_disk', 'probe_disk');
        Config::set('filesystems.disks.probe_disk', [
            'driver' => 's3',
            'endpoint' => 'http://insecure.example.com',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transit is not encrypted');

        app(HistoricImportRuntimeEvidenceCollector::class)->collect($this->attested());
    }

    #[Test]
    public function it_refuses_a_malformed_image_digest(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('name@sha256:');

        app(HistoricImportRuntimeEvidenceCollector::class)->collect(
            $this->attested(['image_digest' => 'crockenhill:latest']),
        );
    }

    /**
     * An empty or throwaway note defeats the point: the artifact would carry an anonymous claim
     * that encryption was confirmed, with no record of how.
     */
    #[Test]
    public function it_refuses_an_insubstantial_encryption_note(): void
    {
        Config::set('media-processing.storage.sermon_disk', 'probe_disk');
        Config::set('filesystems.disks.probe_disk', [
            'driver' => 's3',
            'endpoint' => 'https://secure.example.com',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('substantive note');

        app(HistoricImportRuntimeEvidenceCollector::class)->collect(
            $this->attested(['storage_at_rest_evidence' => 'yes']),
        );
    }

    #[Test]
    public function the_command_requires_every_attested_input(): void
    {
        foreach (['image-digest', 'at-rest-evidence', 'in-transit-evidence'] as $missing) {
            $options = [
                '--image-digest' => 'crockenhill@sha256:'.str_repeat('a', 64),
                '--at-rest-evidence' => 'DO Spaces bucket SSE-S3 confirmed in the control panel',
                '--in-transit-evidence' => 'Endpoint verified https with TLS 1.3 via openssl s_client',
                '--output' => "{$this->root}/evidence-{$missing}.json",
            ];
            unset($options["--{$missing}"]);

            $this->artisan('historic-import:capture-runtime-evidence', $options)
                ->expectsOutputToContain("--{$missing} is required")
                ->assertExitCode(1);

            $this->assertFileDoesNotExist("{$this->root}/evidence-{$missing}.json");
        }
    }

    #[Test]
    public function the_command_refuses_to_overwrite_existing_evidence(): void
    {
        $path = "{$this->root}/existing.json";
        file_put_contents($path, '{}');

        $this->artisan('historic-import:capture-runtime-evidence', [
            '--image-digest' => 'crockenhill@sha256:'.str_repeat('a', 64),
            '--at-rest-evidence' => 'DO Spaces bucket SSE-S3 confirmed in the control panel',
            '--in-transit-evidence' => 'Endpoint verified https with TLS 1.3 via openssl s_client',
            '--output' => $path,
        ])
            ->expectsOutputToContain('Refusing to overwrite')
            ->assertExitCode(1);

        $this->assertSame('{}', file_get_contents($path));
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array{image_digest:string,storage_at_rest_evidence:string,storage_in_transit_evidence:string}
     */
    private function attested(array $overrides = []): array
    {
        return [
            'image_digest' => 'crockenhill@sha256:'.str_repeat('a', 64),
            'storage_at_rest_evidence' => 'DO Spaces bucket SSE-S3 confirmed in the control panel',
            'storage_in_transit_evidence' => 'Endpoint verified https with TLS 1.3 via openssl s_client',
            ...$overrides,
        ];
    }
}
