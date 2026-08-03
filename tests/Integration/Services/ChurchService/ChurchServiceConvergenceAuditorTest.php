<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceReviewSession;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceConvergenceAuditor;
use App\Services\ChurchService\ChurchServiceConvergenceBundleExporter;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceConvergenceAuditorTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_passes_an_exact_reviewed_bundle_without_writing_data(): void
    {
        [$service, $bundle] = $this->reviewedBundle();
        $updatedAt = $service->updated_at;

        $report = app(ChurchServiceConvergenceAuditor::class)->audit($bundle);

        $this->assertTrue($report['passed']);
        $this->assertSame(['services' => 1, 'passed' => 1, 'failed' => 0], $report['totals']);
        $this->assertSame([], $report['services'][0]['differences']);
        $this->assertSame($updatedAt?->toISOString(), $service->fresh()?->updated_at?->toISOString());
    }

    #[Test]
    public function it_reports_field_level_canonical_differences(): void
    {
        [$service, $bundle] = $this->reviewedBundle();
        $service->items()->sole()->forceFill(['title' => 'Production drift'])->saveQuietly();

        $report = app(ChurchServiceConvergenceAuditor::class)->audit($bundle);

        $this->assertFalse($report['passed']);
        $this->assertContains(
            'canonical_manifest.items.0.title',
            collect($report['services'][0]['differences'])->pluck('path')->all(),
        );
    }

    #[Test]
    public function it_ignores_superseded_proposals_during_closeout(): void
    {
        [$service, $bundle] = $this->reviewedBundle();
        ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $service->id,
            'trigger_source_record_id' => $service->sourceRecords()->firstOrFail()->id,
            'status' => ChurchServiceProposalStatus::Stale,
        ]);

        $report = app(ChurchServiceConvergenceAuditor::class)->audit($bundle);

        $this->assertTrue($report['passed']);
        $this->assertSame(
            [],
            collect($report['services'][0]['differences'])
                ->where('path', 'pending_proposals')
                ->all(),
        );
    }

    /** @return array{ChurchService, array<string, mixed>} */
    private function reviewedBundle(): array
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-08-02',
            'service' => 'morning',
            'canonical_revision' => 0,
            'canonical_hash' => null,
        ]);
        $reviewer = User::factory()->create(['email' => 'reviewer@example.com']);
        $normalizer = app(ChurchServiceAssertionNormalizer::class);
        $machineAssertions = $normalizer->normalize([[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Machine title',
        ]], ChurchServiceEvidenceKind::Planned);
        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: 'email-message',
            inputHash: CanonicalJson::hash($machineAssertions),
            assertions: $machineAssertions,
            processingFingerprint: ['version' => 1],
        ));
        $service = $service->fresh() ?? $service;
        $preReviewHash = $service->canonical_hash;
        $manualAssertions = $normalizer->normalize([[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Reviewed title',
        ]], ChurchServiceEvidenceKind::Manual);
        $manual = app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Manual,
            sourceKey: 'review:portable-review',
            inputHash: CanonicalJson::hash($manualAssertions),
            assertions: $manualAssertions,
            processingFingerprint: ['format' => 'manual-review', 'version' => 1],
            serviceContent: ['summary' => null, 'notices' => [], 'chapter_markers' => []],
            createdByUserId: $reviewer->id,
        ));
        $service = $service->fresh() ?? $service;
        $service->forceFill([
            'reviewed_canonical_revision' => $service->canonical_revision,
        ])->saveQuietly();
        ChurchServiceReviewSession::factory()->create([
            'church_service_id' => $service->id,
            'review_uuid' => 'portable-review',
            'base_canonical_revision' => $service->canonical_revision - 1,
            'base_canonical_hash' => $preReviewHash,
            'manual_source_record_id' => $manual->sourceRecord->id,
            'resulting_canonical_revision' => $service->canonical_revision,
            'resulting_canonical_hash' => $service->canonical_hash,
            'reviewed_by_user_id' => $reviewer->id,
            'completed_at' => now(),
        ]);
        $bundle = app(ChurchServiceConvergenceBundleExporter::class)->export(
            [$service->id],
            str_repeat('1', 64),
            str_repeat('2', 64),
            ['projector_version' => 1],
        );

        return [$service, $bundle];
    }
}
