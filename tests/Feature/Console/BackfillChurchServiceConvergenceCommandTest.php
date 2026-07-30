<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\ChurchServiceReviewState;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceSourceRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillChurchServiceConvergenceCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_backfills_legacy_evidence_and_each_pending_proposal_idempotently(): void
    {
        Storage::fake('local');

        $service = ChurchService::factory()->create([
            'pending_structure_merge_source' => 'email',
            'import_metadata' => [
                'pending_structure_merge' => [
                    'created_at' => '2026-07-28T10:00:00+00:00',
                    'proposed_items' => [
                        ['position' => 1, 'type' => 'songs', 'title' => 'Current proposal'],
                    ],
                    'conflicts' => [['reason' => 'order_conflict']],
                    'superseded_proposals' => [
                        [
                            'incoming_source' => 'openlp',
                            'created_at' => '2026-07-27T10:00:00+00:00',
                            'proposed_items' => [
                                ['position' => 1, 'type' => 'songs', 'title' => 'Earlier proposal'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        ChurchServiceItem::factory()->for($service)->create([
            'position' => 1,
            'type' => 'songs',
            'source' => 'email',
            'title' => 'Amazing Grace',
            'metadata' => [
                'source_evidence' => [
                    'email' => ['titles' => ['Amazing Grace']],
                    'livestream' => ['titles' => ['Amazing Grace']],
                ],
            ],
        ]);

        $this->artisan('service-tracking:backfill-convergence', ['--report' => 'private/reports/wp6.json'])
            ->assertSuccessful();

        $service->refresh();

        $this->assertSame(1, $service->canonical_revision);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $service->canonical_hash);
        $this->assertNull($service->reviewed_canonical_revision);
        $this->assertSame(ChurchServiceOccurrenceState::PlannedAndObserved, $service->items()->firstOrFail()->occurrence_state);
        $this->assertSame(2, ChurchServiceMergeProposal::query()->whereBelongsTo($service)->count());
        $this->assertSame(4, ChurchServiceSourceRecord::query()->whereBelongsTo($service)->count());
        $this->assertSame(
            4,
            ChurchServiceSourceRecord::query()
                ->whereBelongsTo($service)
                ->withCount('assertions')
                ->get()
                ->sum('assertions_count'),
        );
        $this->assertTrue(
            ChurchServiceSourceRecord::query()
                ->whereBelongsTo($service)
                ->whereIn('source', ['email', 'livestream'])
                ->get()
                ->every(fn (ChurchServiceSourceRecord $record): bool => ! $record->payload_complete),
        );

        $counts = [
            ChurchServiceSourceRecord::query()->count(),
            ChurchServiceMergeProposal::query()->count(),
        ];

        $this->artisan('service-tracking:backfill-convergence', ['--report' => 'private/reports/wp6-rerun.json'])
            ->assertSuccessful();

        $this->assertSame($counts, [
            ChurchServiceSourceRecord::query()->count(),
            ChurchServiceMergeProposal::query()->count(),
        ]);
        Storage::disk('local')->assertExists('private/reports/wp6.json');
    }

    #[Test]
    public function it_only_marks_a_canonical_revision_reviewed_when_normalized_review_state_proves_completion(): void
    {
        Storage::fake('local');

        $reviewed = ChurchService::factory()->create([
            'source' => 'manual',
            'review_state' => ChurchServiceReviewState::Reviewed,
            'manual_reviewed_at' => now(),
            'manual_reviewed_by_user_id' => null,
        ]);
        ChurchServiceItem::factory()->for($reviewed)->create(['source' => 'manual']);

        $incompletelyReviewed = ChurchService::factory()->create([
            'review_state' => ChurchServiceReviewState::Reopened,
            'manual_reviewed_at' => now()->subDay(),
            'manual_review_reopened_at' => now(),
            'manual_review_reopened_by_source' => 'livestream',
        ]);
        ChurchServiceItem::factory()->for($incompletelyReviewed)->create();

        $this->artisan('service-tracking:backfill-convergence', ['--report' => 'private/wp6.json'])
            ->assertSuccessful();

        $this->assertSame(
            $reviewed->fresh()->canonical_revision,
            $reviewed->fresh()->reviewed_canonical_revision,
        );
        $this->assertNull($incompletelyReviewed->fresh()->reviewed_canonical_revision);
    }

    #[Test]
    public function shadow_projection_reports_item_level_differences_without_mutating_canonical_rows(): void
    {
        Storage::fake('local');

        $service = ChurchService::factory()->create([
            'summary' => 'Existing summary',
            'canonical_revision' => 7,
            'canonical_hash' => str_repeat('a', 64),
        ]);
        $item = ChurchServiceItem::factory()->for($service)->create([
            'position' => 1,
            'source' => 'email',
            'title' => 'Canonical title',
            'metadata' => [
                'source_evidence' => [
                    'email' => ['titles' => ['Different evidence title']],
                ],
            ],
        ]);

        $this->artisan('service-tracking:backfill-convergence', [
            '--shadow-only' => true,
            '--report' => 'private/reports/wp6-shadow.json',
        ])->assertSuccessful();

        $this->assertSame(7, $service->fresh()->canonical_revision);
        $this->assertSame(str_repeat('a', 64), $service->fresh()->canonical_hash);
        $this->assertSame('Canonical title', $item->fresh()->title);

        $report = json_decode(
            Storage::disk('local')->get('private/reports/wp6-shadow.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(1, $report['summary']['services_with_differences']);
        $this->assertSame('canonical_and_projected_items_differ', $report['services'][0]['differences'][0]['explanation']);
    }
}
