<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceCanonicalFinalization;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceConvergenceAuditor;
use App\Services\ChurchService\ChurchServiceConvergenceBundle;
use App\Services\ChurchService\ChurchServiceConvergenceBundleExporter;
use App\Services\ChurchService\ChurchServiceConvergenceBundleImporter;
use App\Services\ChurchService\ChurchServiceEvidenceSet;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceConvergenceBundleExporterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exports_a_conflict_free_automatic_finalisation_without_manual_review(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-08-03',
            'service' => 'morning',
        ]);
        $assertions = app(ChurchServiceAssertionNormalizer::class)->normalize([[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Welcome',
        ]], ChurchServiceEvidenceKind::Planned);

        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: 'email-automatic',
            inputHash: CanonicalJson::hash($assertions),
            assertions: $assertions,
            processingFingerprint: ['format' => 'test-media', 'version' => 1],
        ));

        $service = $service->fresh() ?? $service;
        $this->assertSame(ChurchServiceCanonicalFinalization::Automatic, $service->canonical_finalization);
        $this->assertNull($service->reviewed_canonical_revision);
        $this->assertSame(ChurchServiceProjector::PROJECTION_POLICY_VERSION, $service->projection_policy_version);
        $this->assertSame(0, $service->reviewSessions()->count());

        $bundle = app(ChurchServiceConvergenceBundleExporter::class)->export(
            [$service->id],
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['format' => 'test-media', 'version' => 1],
        );
        $payload = $bundle['services'][0];

        $this->assertSame('automatic', $payload['finalization']);
        $this->assertSame(
            ['format' => ChurchServiceProjector::PROJECTION_POLICY_FORMAT, 'version' => ChurchServiceProjector::PROJECTION_POLICY_VERSION],
            $payload['projection_policy'],
        );
        $this->assertNull($payload['manual_revision']);
        $this->assertNull($payload['review']);
        $this->assertSame($service->canonical_hash, $payload['pre_review_hash']);
        $this->assertSame($service->canonical_hash, $payload['resulting_canonical_hash']);
        app(ChurchServiceConvergenceBundle::class)->validate($bundle);

        $audit = app(ChurchServiceConvergenceAuditor::class)->audit($bundle);

        $this->assertTrue($audit['passed']);
    }

    #[Test]
    public function it_exports_a_service_whose_source_lineage_has_been_superseded(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-08-17',
            'service' => 'morning',
        ]);

        foreach (['Welcome', 'Welcome Everyone'] as $title) {
            $this->ingestEmail($service, $title, 'email-corrected');
        }

        $service = $service->fresh() ?? $service;
        $this->assertSame(2, $service->sourceRecords()->count());

        $bundle = app(ChurchServiceConvergenceBundleExporter::class)->export(
            [$service->id],
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['format' => 'test-media', 'version' => 1],
        );

        // Only the active leaf may stand behind the exported evidence set.
        $this->assertSame(
            app(ChurchServiceEvidenceSet::class)->hash($service->sourceRecords()->get()),
            $bundle['services'][0]['evidence_set_hash'],
        );
        $this->assertTrue(app(ChurchServiceConvergenceAuditor::class)->audit($bundle)['passed']);
    }

    #[Test]
    public function an_automatic_bundle_round_trips_through_the_importer(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-08-24',
            'service' => 'morning',
        ]);
        $this->ingestEmail($service, 'Welcome', 'email-roundtrip');
        $service = $service->fresh() ?? $service;

        $bundle = app(ChurchServiceConvergenceBundleExporter::class)->export(
            [$service->id],
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['format' => 'test-media', 'version' => 1],
        );

        $importer = app(ChurchServiceConvergenceBundleImporter::class);
        $plan = $importer->prepareService($bundle);

        $this->assertSame('already_present', $plan->classification);
        $this->assertNull($plan->reviewer);

        $imported = $importer->persistPreparedService($plan, $plan->planHash);

        $this->assertSame($service->canonical_hash, $imported->canonical_hash);
        $this->assertSame(ChurchServiceCanonicalFinalization::Automatic, $imported->canonical_finalization);
        $this->assertSame(0, $imported->reviewSessions()->count());
    }

    #[Test]
    public function an_automatic_import_is_blocked_when_production_evidence_differs(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-08-31',
            'service' => 'morning',
        ]);
        $this->ingestEmail($service, 'Welcome', 'email-blocked');
        $service = $service->fresh() ?? $service;

        $bundle = app(ChurchServiceConvergenceBundleExporter::class)->export(
            [$service->id],
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['format' => 'test-media', 'version' => 1],
        );

        $this->ingestEmail($service, 'Welcome and notices', 'email-blocked');

        $plan = app(ChurchServiceConvergenceBundleImporter::class)->prepareService($bundle);

        $this->assertSame('blocked_difference', $plan->classification);
    }

    #[Test]
    public function a_conflicting_source_reopens_an_automatic_service_for_review(): void
    {
        $service = ChurchService::factory()->create();
        $normalizer = app(ChurchServiceAssertionNormalizer::class);
        $emailAssertions = $normalizer->normalize([
            ['position' => 1, 'type' => 'custom', 'title' => 'Prayer'],
            ['position' => 2, 'type' => 'custom', 'title' => 'Prayer'],
        ], ChurchServiceEvidenceKind::Planned);

        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: 'email-automatic',
            inputHash: CanonicalJson::hash($emailAssertions),
            assertions: $emailAssertions,
            processingFingerprint: ['format' => 'test-media', 'version' => 1],
        ));

        $openLpAssertions = $normalizer->normalize([
            ['position' => 1, 'type' => 'custom', 'title' => 'Prayer'],
        ], ChurchServiceEvidenceKind::Planned);
        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::OpenLp,
            sourceKey: 'openlp-automatic',
            inputHash: CanonicalJson::hash($openLpAssertions),
            assertions: $openLpAssertions,
            processingFingerprint: ['format' => 'test-media', 'version' => 1],
        ));

        $service = $service->fresh() ?? $service;

        $this->assertNull($service->canonical_finalization);
        $this->assertTrue($service->needs_review);
        $this->assertSame(ChurchServiceProposalStatus::Pending, $service->mergeProposals()->sole()->status);
    }

    private function ingestEmail(ChurchService $service, string $title, string $sourceKey): void
    {
        $assertions = app(ChurchServiceAssertionNormalizer::class)->normalize([[
            'position' => 1,
            'type' => 'custom',
            'title' => $title,
        ]], ChurchServiceEvidenceKind::Planned);

        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: $sourceKey,
            inputHash: CanonicalJson::hash($assertions),
            assertions: $assertions,
            processingFingerprint: ['format' => 'test-media', 'version' => 1],
        ));
    }
}
