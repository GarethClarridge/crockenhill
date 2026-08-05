<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceCanonicalFinalization;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceReviewState;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceReviewSession;
use App\Models\Song;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ChurchServiceProposalCensus;
use App\Services\ChurchService\CurrentEraChurchServiceReprojection;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ReprojectCurrentEraChurchServicesCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_reprojects_the_current_era_without_dispatching_work_and_reports_unchanged_services_as_exact(): void
    {
        Storage::fake('local');
        $service = ChurchService::factory()->create();
        $this->ingest($service, 'Welcome');
        $fingerprints = $service->sourceRecords()->orderBy('id')->pluck('processing_fingerprint')->all();

        Bus::fake();
        Queue::fake();
        Event::fake();
        Http::preventStrayRequests();

        $this->artisan('service-tracking:reproject-current-era', [
            '--apply' => true,
            '--report' => 'private/reports/current-era-reprojection.json',
        ])->assertSuccessful();

        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        $this->assertSame($fingerprints, $service->sourceRecords()->orderBy('id')->pluck('processing_fingerprint')->all());

        $report = $this->report('private/reports/current-era-reprojection.json');
        $serviceReport = $this->serviceReport($report, $service);

        $this->assertSame('already_present', $serviceReport['classification']);
        $this->assertSame([], $serviceReport['item_differences']);
        $this->assertSame([], $serviceReport['residual_item_differences']);
        $this->assertAllGatesPassed($report);
        $this->assertSame(ChurchServiceCanonicalFinalization::Automatic, $service->fresh()->canonical_finalization);

        $canonicalRevision = $service->fresh()->canonical_revision;

        $this->artisan('service-tracking:reproject-current-era', [
            '--apply' => true,
            '--report' => 'private/reports/current-era-reprojection-rerun.json',
        ])->assertSuccessful();

        $this->assertSame($canonicalRevision, $service->fresh()->canonical_revision);
        $this->assertSame(
            'already_present',
            $this->serviceReport($this->report('private/reports/current-era-reprojection-rerun.json'), $service)['classification'],
        );
    }

    #[Test]
    public function it_reports_a_song_item_already_projected_by_the_repaired_projector_as_an_exact_match(): void
    {
        Storage::fake('local');
        $song = Song::factory()->create();
        $service = ChurchService::factory()->create();
        $this->ingestSong($service, $song);

        $this->assertSame($song->id, $service->items()->sole()->song_id);

        $this->artisan('service-tracking:reproject-current-era', [
            '--report' => 'private/reports/current-era-song.json',
        ])->assertSuccessful();

        $report = $this->report('private/reports/current-era-song.json');
        $serviceReport = $this->serviceReport($report, $service);

        $this->assertSame([], $serviceReport['item_differences']);
        $this->assertSame('already_present', $serviceReport['classification']);
        $this->assertSame(0, $report['summary']['services_with_item_differences']);
        $this->assertAllGatesPassed($report);
    }

    #[Test]
    public function it_reports_item_level_differences_even_when_the_item_counts_match(): void
    {
        Storage::fake('local');
        $service = ChurchService::factory()->create();
        $this->ingest($service, 'Evidence title');
        $service->items()->sole()->update(['title' => 'Incorrect canonical title']);

        $this->artisan('service-tracking:reproject-current-era', [
            '--report' => 'private/reports/current-era-diff.json',
        ])->assertSuccessful();

        $report = $this->report('private/reports/current-era-diff.json');
        $serviceReport = $this->serviceReport($report, $service);

        $this->assertSame('blocked_difference', $serviceReport['classification']);
        $this->assertSame('updated_item', $serviceReport['item_differences'][0]['type']);
        $this->assertSame('title', $serviceReport['item_differences'][0]['fields'][0]['field']);
        $this->assertSame(1, $report['summary']['services_with_item_differences']);
        $this->assertSame('Incorrect canonical title', $service->items()->sole()->title);
        $this->assertAllGatesPassed($report);
    }

    #[Test]
    public function it_keeps_the_repair_diff_and_reports_no_residual_difference_once_applied(): void
    {
        Storage::fake('local');
        $service = ChurchService::factory()->create();
        $this->ingest($service, 'Evidence title');
        $service->items()->sole()->update(['title' => 'Incorrect canonical title']);

        $this->artisan('service-tracking:reproject-current-era', [
            '--apply' => true,
            '--report' => 'private/reports/current-era-applied.json',
        ])->assertSuccessful();

        $report = $this->report('private/reports/current-era-applied.json');
        $serviceReport = $this->serviceReport($report, $service);

        $this->assertTrue($serviceReport['applied']);
        $this->assertSame('blocked_difference', $serviceReport['classification']);
        $this->assertSame('title', $serviceReport['item_differences'][0]['fields'][0]['field']);
        $this->assertSame([], $serviceReport['residual_item_differences']);
        $this->assertSame([], $serviceReport['residual_service_differences']);
        $this->assertSame(1, $report['summary']['services_with_item_differences']);
        $this->assertSame(0, $report['summary']['services_with_residual_differences']);
        $this->assertSame('Evidence title', $service->items()->sole()->title);
        $this->assertAllGatesPassed($report);
    }

    #[Test]
    public function it_classifies_a_service_without_normalized_evidence_as_a_conflict(): void
    {
        Storage::fake('local');
        $service = ChurchService::factory()->create();

        $this->artisan('service-tracking:reproject-current-era', [
            '--apply' => true,
            '--report' => 'private/reports/current-era-no-evidence.json',
        ])->assertSuccessful();

        $report = $this->report('private/reports/current-era-no-evidence.json');
        $serviceReport = $this->serviceReport($report, $service);

        $this->assertSame('conflict', $serviceReport['classification']);
        $this->assertFalse($serviceReport['applied']);
        $this->assertNull($serviceReport['projection_policy']);
        $this->assertSame(1, $report['summary']['conflict']);
        $this->assertAllGatesPassed($report);
    }

    #[Test]
    public function it_reopens_legacy_b13_acceptances_without_reopening_explicit_decisions(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $service = ChurchService::factory()->create([
            'needs_review' => false,
            'review_state' => ChurchServiceReviewState::Reviewed,
            'manual_reviewed_at' => now()->subDay(),
            'manual_reviewed_by_user_id' => $user->id,
            'reviewed_canonical_revision' => 1,
            'canonical_finalization' => ChurchServiceCanonicalFinalization::Manual,
        ]);
        $legacyProposal = ChurchServiceMergeProposal::factory()->for($service)->create([
            'status' => ChurchServiceProposalStatus::Accepted,
            'resolved_by_user_id' => $user->id,
            'resolved_at' => now()->subDay(),
        ]);
        $explicitProposal = ChurchServiceMergeProposal::factory()->for($service)->create([
            'status' => ChurchServiceProposalStatus::Accepted,
            'resolved_by_user_id' => $user->id,
            'resolved_at' => now(),
        ]);
        $this->legacyReviewSession($service, $user, [$legacyProposal->id]);
        $this->reviewSessionWithDispositions($service, $user, [$explicitProposal->id]);

        $this->artisan('service-tracking:reproject-current-era', [
            '--apply' => true,
            '--report' => 'private/reports/current-era-b13.json',
        ])->assertSuccessful();

        $legacyProposal->refresh();
        $explicitProposal->refresh();
        $service->refresh();

        $this->assertSame(ChurchServiceProposalStatus::Pending, $legacyProposal->status);
        $this->assertNull($legacyProposal->resolved_by_user_id);
        $this->assertNull($legacyProposal->resolved_at);
        $this->assertSame(ChurchServiceProposalStatus::Accepted, $explicitProposal->status);
        $this->assertTrue($service->needs_review);
        $this->assertSame(ChurchServiceReviewState::Reopened, $service->review_state);
        $this->assertNotNull($service->manual_review_reopened_at);
        $this->assertSame('current_era_reprojection', $service->manual_review_reopened_by_source);
        $this->assertNull($service->canonical_finalization);
        $this->assertNull($service->reviewed_canonical_revision);
        $this->assertCount(1, app(ChurchServiceProposalCensus::class)->build());

        $report = $this->report('private/reports/current-era-b13.json');
        $this->assertSame(1, $this->serviceReport($report, $service)['b13_proposals_reopened']);
        $this->assertSame(0, $report['summary']['b13_proposals_pending_reopening']);
        $this->assertAllGatesPassed($report);
    }

    #[Test]
    public function it_reopens_a_legacy_acceptance_on_a_service_that_was_never_manually_reviewed(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        // Reviews recorded before B13 was fixed never wrote manual_reviewed_at or
        // review_state, so this is the ordinary shape of the affected corpus.
        $service = ChurchService::factory()->create([
            'needs_review' => false,
            'review_state' => ChurchServiceReviewState::NotReviewed,
            'manual_reviewed_at' => null,
            'reviewed_canonical_revision' => 1,
        ]);
        $legacyProposal = ChurchServiceMergeProposal::factory()->for($service)->create([
            'status' => ChurchServiceProposalStatus::Accepted,
            'resolved_by_user_id' => $user->id,
            'resolved_at' => now()->subDay(),
        ]);
        $this->legacyReviewSession($service, $user, [$legacyProposal->id]);

        $this->artisan('service-tracking:reproject-current-era', [
            '--apply' => true,
            '--report' => 'private/reports/current-era-never-reviewed.json',
        ])->assertSuccessful();

        $legacyProposal->refresh();
        $service->refresh();

        $this->assertSame(ChurchServiceProposalStatus::Pending, $legacyProposal->status);
        $this->assertTrue($service->needs_review);
        $this->assertSame('pending_evidence_review', $service->review_reason);
        $this->assertNull($service->reviewed_canonical_revision);

        // A service nobody manually reviewed cannot be reopened: the review_state
        // check constraint requires a completed review behind every reopening.
        $this->assertSame(ChurchServiceReviewState::NotReviewed, $service->review_state);
        $this->assertNull($service->manual_review_reopened_at);
        $this->assertNull($service->manual_review_reopened_by_source);

        $report = $this->report('private/reports/current-era-never-reviewed.json');
        $this->assertSame(1, $this->serviceReport($report, $service)['b13_proposals_reopened']);
        $this->assertAllGatesPassed($report);
    }

    #[Test]
    public function it_keeps_a_legacy_acceptance_a_reviewer_reaffirmed_after_the_fix(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $service = ChurchService::factory()->create();
        $proposal = ChurchServiceMergeProposal::factory()->for($service)->create([
            'status' => ChurchServiceProposalStatus::Accepted,
            'resolved_by_user_id' => $user->id,
            'resolved_at' => now(),
        ]);

        $this->legacyReviewSession($service, $user, [$proposal->id]);
        $this->reviewSessionWithDispositions($service, $user, [$proposal->id]);

        $this->artisan('service-tracking:reproject-current-era', [
            '--apply' => true,
            '--report' => 'private/reports/current-era-reaffirmed.json',
        ])->assertSuccessful();

        $proposal->refresh();
        $service->refresh();

        $this->assertSame(ChurchServiceProposalStatus::Accepted, $proposal->status);
        $this->assertSame($user->id, $proposal->resolved_by_user_id);
        $this->assertNotNull($proposal->resolved_at);
        $this->assertSame(0, $this->serviceReport(
            $this->report('private/reports/current-era-reaffirmed.json'),
            $service,
        )['b13_proposals_reopened']);
    }

    #[Test]
    public function it_records_a_partial_run_and_a_resume_point_when_a_service_fails(): void
    {
        Storage::fake('local');
        $first = ChurchService::factory()->create();
        $second = ChurchService::factory()->create();

        $this->mock(CurrentEraChurchServiceReprojection::class, function (MockInterface $mock) use ($first, $second): void {
            $mock->shouldReceive('reproject')
                ->once()
                ->with(Mockery::on(fn (ChurchService $service): bool => $service->is($first)), false)
                ->andReturn($this->exactServiceResult($first));
            $mock->shouldReceive('reproject')
                ->once()
                ->with(Mockery::on(fn (ChurchService $service): bool => $service->is($second)), false)
                ->andThrow(new RuntimeException('Check constraint violated.'));
        });

        $this->artisan('service-tracking:reproject-current-era', [
            '--report' => 'private/reports/current-era-failure.json',
        ])->assertFailed();

        $report = $this->report('private/reports/current-era-failure.json');

        $this->assertFalse($report['gates']['run_completed']);
        $this->assertCount(1, $report['services']);
        $this->assertSame($second->id, $report['failure']['church_service_id']);
        $this->assertSame('Check constraint violated.', $report['failure']['message']);
        $this->assertSame($first->id, $report['resume']['last_completed_service_id']);
        $this->assertSame($second->id, $report['resume']['resume_from_service_id']);
    }

    #[Test]
    public function it_resumes_from_the_requested_service_id(): void
    {
        Storage::fake('local');
        $first = ChurchService::factory()->create();
        $second = ChurchService::factory()->create();

        $this->artisan('service-tracking:reproject-current-era', [
            '--from-service-id' => (string) $second->id,
            '--report' => 'private/reports/current-era-resume.json',
        ])->assertSuccessful();

        $report = $this->report('private/reports/current-era-resume.json');

        $this->assertSame([$second->id], array_column($report['services'], 'church_service_id'));
        $this->assertNotContains($first->id, array_column($report['services'], 'church_service_id'));
    }

    #[Test]
    public function it_rejects_a_report_path_outside_private_storage(): void
    {
        Storage::fake('local');

        $this->artisan('service-tracking:reproject-current-era', [
            '--report' => 'public/current-era.json',
        ])->assertFailed();

        Storage::disk('local')->assertMissing('public/current-era.json');
    }

    #[Test]
    public function it_rejects_a_non_numeric_resume_option(): void
    {
        Storage::fake('local');

        $this->artisan('service-tracking:reproject-current-era', [
            '--from-service-id' => 'first',
            '--report' => 'private/reports/current-era-invalid.json',
        ])->assertFailed();

        Storage::disk('local')->assertMissing('private/reports/current-era-invalid.json');
    }

    private function ingest(ChurchService $service, string $title): void
    {
        $this->ingestAssertions($service, [[
            'source_position' => 1,
            'evidence_kind' => ChurchServiceEvidenceKind::Planned->value,
            'type' => 'custom',
            'title' => $title,
            'stable_key' => 'welcome',
        ]]);
    }

    private function ingestSong(ChurchService $service, Song $song): void
    {
        $this->ingestAssertions($service, [[
            'source_position' => 1,
            'evidence_kind' => ChurchServiceEvidenceKind::Planned->value,
            'type' => 'song',
            'section_type' => 'song',
            'title' => $song->title,
            'song_id' => $song->id,
            'song_canonical_key' => $song->canonical_key,
        ]]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function ingestAssertions(ChurchService $service, array $items): void
    {
        $assertions = app(ChurchServiceAssertionNormalizer::class)->normalize(
            $items,
            ChurchServiceEvidenceKind::Planned,
        );

        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: 'current-era:'.Str::uuid(),
            inputHash: CanonicalJson::hash($assertions),
            assertions: $assertions,
            processingFingerprint: ['format' => 'test', 'version' => 1],
        ));
    }

    /**
     * A review recorded before B13 was fixed: selected proposal ids, no dispositions.
     *
     * @param  list<int>  $proposalIds
     */
    private function legacyReviewSession(ChurchService $service, User $user, array $proposalIds): void
    {
        ChurchServiceReviewSession::factory()->for($service)->create([
            'included_proposal_ids' => $proposalIds,
            'proposal_dispositions' => null,
            'completed_at' => now()->subDay(),
            'reviewed_by_user_id' => $user->id,
        ]);
    }

    /** @param list<int> $proposalIds */
    private function reviewSessionWithDispositions(ChurchService $service, User $user, array $proposalIds): void
    {
        ChurchServiceReviewSession::factory()->for($service)->create([
            'included_proposal_ids' => $proposalIds,
            'proposal_dispositions' => array_map(static fn (int $proposalId): array => [
                'proposal_id' => $proposalId,
                'disposition' => 'accepted',
                'rationale' => 'Explicitly accepted.',
            ], $proposalIds),
            'completed_at' => now(),
            'reviewed_by_user_id' => $user->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function exactServiceResult(ChurchService $service): array
    {
        return [
            'church_service_id' => $service->getKey(),
            'identity' => "{$service->date->toDateString()}|{$service->service->value}",
            'classification' => 'already_present',
            'reason' => 'The current canonical result already exactly matches the repaired projector.',
            'applied' => false,
            'projection_policy' => null,
            'projection_conflict_count' => 0,
            'item_differences' => [],
            'service_differences' => [],
            'residual_item_differences' => [],
            'residual_service_differences' => [],
            'b13_proposals_reopened' => 0,
            'b13_proposals_pending_reopening' => 0,
        ];
    }

    /** @param array<string, mixed> $report */
    private function assertAllGatesPassed(array $report): void
    {
        $this->assertSame([], array_keys(array_filter(
            $report['gates'],
            static fn (bool $passed): bool => ! $passed,
        )));
    }

    /** @return array<string, mixed> */
    private function report(string $path): array
    {
        return json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function serviceReport(array $report, ChurchService $service): array
    {
        return collect($report['services'])
            ->firstOrFail(fn (array $entry): bool => $entry['identity'] === "{$service->date->toDateString()}|{$service->service->value}");
    }
}
