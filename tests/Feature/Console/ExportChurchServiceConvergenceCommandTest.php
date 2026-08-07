<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExportChurchServiceConvergenceCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The command resolves --output against `realpath(storage_path('app/private'))`
     * before it ever looks at --media-bundle, and that directory is untracked, so a
     * fresh checkout does not have it. Without this every path assertion in the class
     * reports the missing-output-parent failure instead of the one it is testing.
     */
    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(storage_path('app/private'));
    }

    #[Test]
    public function it_rejects_an_export_without_reviewed_service_ids(): void
    {
        $this->artisan('service-tracking:export-convergence', [
            '--batch-hash' => str_repeat('1', 64),
            '--media-bundle' => storage_path('app/private/bundle-a.json'),
            '--output' => storage_path('app/private/r8-bundle-b.json'),
        ])
            ->expectsOutput('--service-ids is required.')
            ->assertFailed();
    }

    #[Test]
    public function it_rejects_an_export_whose_media_bundle_cannot_be_read(): void
    {
        $this->artisan('service-tracking:export-convergence', [
            '--service-ids' => '1',
            '--batch-hash' => str_repeat('1', 64),
            '--media-bundle' => storage_path('app/private/absent-bundle-a.json'),
            '--output' => storage_path('app/private/r8-bundle-b.json'),
        ])
            ->expectsOutput('--media-bundle must be a readable Bundle A path.')
            ->assertFailed();
    }

    /**
     * Convergence and the auditor both refuse a pair whose fingerprints do not
     * hash-match. Bundle B therefore has to take both the media-bundle hash and
     * the fingerprint from Bundle A itself, never from a retyped option.
     */
    #[Test]
    public function it_takes_the_media_bundle_hash_and_fingerprint_from_bundle_a(): void
    {
        $service = ChurchService::factory()->create(['date' => '2021-04-11', 'service' => 'morning']);
        $assertions = app(ChurchServiceAssertionNormalizer::class)->normalize([[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Welcome',
        ]], ChurchServiceEvidenceKind::Planned);

        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: 'email-fingerprint-derivation',
            inputHash: CanonicalJson::hash($assertions),
            assertions: $assertions,
            processingFingerprint: ['format' => 'test-media', 'version' => 1],
        ));

        $mediaBundlePath = $this->writeMediaBundle();
        $outputPath = storage_path('app/private/r8-bundle-b.json');

        $this->artisan('service-tracking:export-convergence', [
            '--service-ids' => (string) $service->id,
            '--batch-hash' => str_repeat('1', 64),
            '--media-bundle' => $mediaBundlePath,
            '--output' => $outputPath,
        ])->assertSuccessful();

        try {
            $mediaBundle = json_decode(File::get($mediaBundlePath), true, flags: JSON_THROW_ON_ERROR);
            $convergenceBundle = json_decode(File::get($outputPath), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame($mediaBundle['bundle_hash'], $convergenceBundle['media_bundle_hash']);
            $this->assertSame($mediaBundle['processing_fingerprint'], $convergenceBundle['processing_fingerprint']);
        } finally {
            File::delete([$mediaBundlePath, $outputPath]);
        }
    }

    private function writeMediaBundle(): string
    {
        $path = storage_path('app/private/r8-bundle-a-fixture.json');

        $bundle = app(HistoricProcessingResultBundle::class)->make(
            str_repeat('1', 64),
            ['format' => 'crockenhill.historic-media-processing.v1', 'source_manifest_hash' => str_repeat('a', 64)],
            [[
                'date' => '2021-04-11',
                'service' => 'morning',
                'source_manifest_hash' => str_repeat('a', 64),
                'evidence_set_hash' => str_repeat('b', 64),
                'pre_review_hash' => str_repeat('c', 64),
                'media_graph' => ['processing_key' => 'fixture-run'],
                'livestream_source_revision' => [],
                'assets' => [],
            ]],
        );

        File::put($path, json_encode($bundle, JSON_THROW_ON_ERROR));

        return $path;
    }
}
