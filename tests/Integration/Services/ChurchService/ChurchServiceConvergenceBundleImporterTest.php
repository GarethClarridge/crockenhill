<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceReviewSession;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceConvergenceBundleExporter;
use App\Services\ChurchService\ChurchServiceConvergenceBundleImporter;
use App\Services\ChurchService\ChurchServiceProjectionPersister;
use App\Services\ChurchService\ChurchServiceProjector;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceConvergenceBundleImporterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_applies_a_reviewed_manual_revision_and_reruns_as_a_no_op(): void
    {
        [$service, $reviewer, $bundle, $preReviewHash] = $this->reviewedBundle();
        $this->restoreMachineBase($service, $preReviewHash);
        $importer = app(ChurchServiceConvergenceBundleImporter::class);

        $plan = $importer->prepareService($bundle);
        $applied = $importer->persistPreparedService($plan, $plan->planHash);

        $this->assertSame('apply', $plan->classification);
        $this->assertSame('Reviewed title', $applied->items()->sole()->title);
        $this->assertSame($reviewer->id, $applied->reviewSessions()->sole()->reviewed_by_user_id);

        $secondPlan = $importer->prepareService($bundle);
        $second = $importer->persistPreparedService($secondPlan, $secondPlan->planHash);

        $this->assertSame('already_present', $secondPlan->classification);
        $this->assertSame($applied->canonical_hash, $second->canonical_hash);
        $this->assertSame(1, $second->reviewSessions()->count());
    }

    #[Test]
    public function it_blocks_when_the_machine_evidence_base_differs(): void
    {
        [$service, , $bundle, $preReviewHash] = $this->reviewedBundle();
        $this->restoreMachineBase($service, $preReviewHash);
        $service->sourceRecords()->where('source', ChurchServiceSource::Email->value)->update([
            'processing_fingerprint' => ['version' => 999],
        ]);

        $plan = app(ChurchServiceConvergenceBundleImporter::class)->prepareService($bundle);

        $this->assertSame('blocked_difference', $plan->classification);
        $this->assertStringContainsString('machine evidence', $plan->reason);
    }

    #[Test]
    public function it_joins_a_caller_owned_transaction_and_enforces_the_plan_hash(): void
    {
        [$service, , $bundle, $preReviewHash] = $this->reviewedBundle();
        $this->restoreMachineBase($service, $preReviewHash);
        $importer = app(ChurchServiceConvergenceBundleImporter::class);
        $plan = $importer->prepareService($bundle);

        try {
            DB::transaction(function () use ($importer, $plan): void {
                $importer->persistPreparedService($plan, $plan->planHash);
                throw new \RuntimeException('rollback after Bundle B');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback after Bundle B', $exception->getMessage());
        }

        $this->assertDatabaseMissing('church_service_review_sessions', ['review_uuid' => 'portable-review']);
        $this->assertDatabaseMissing('church_service_source_records', ['source_key' => 'review:portable-review']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('plan hash does not match');
        $importer->persistPreparedService($plan, str_repeat('0', 64));
    }

    /** @return array{ChurchService, User, array<string, mixed>, string} */
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
        $service->forceFill(['reviewed_canonical_revision' => $service->canonical_revision])->saveQuietly();
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

        return [$service, $reviewer, $bundle, $preReviewHash];
    }

    private function restoreMachineBase(ChurchService $service, string $preReviewHash): void
    {
        $service->reviewSessions()->delete();
        $manuals = $service->sourceRecords()->where('source', ChurchServiceSource::Manual->value)->get();

        foreach ($manuals as $manual) {
            $manual->assertions()->delete();
            $manual->delete();
        }

        $service->items()->delete();
        $service->forceFill([
            'canonical_revision' => 0,
            'canonical_hash' => null,
            'reviewed_canonical_revision' => null,
        ])->saveQuietly();
        $records = $service->sourceRecords()->with(['assertions', 'assertions.sourceRecord'])->get();
        app(ChurchServiceProjectionPersister::class)->apply(
            $service,
            app(ChurchServiceProjector::class)->project($records),
        );
        $this->assertSame($preReviewHash, $service->fresh()->canonical_hash);
    }
}
