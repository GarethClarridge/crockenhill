<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ChurchServiceCanonicalConflictReason;
use App\Enums\ChurchServiceCanonicalConflictState;
use App\Enums\ChurchServiceReviewState;
use App\Models\ChurchService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The review/canonical-conflict columns themselves are proven to exist by
     * {@see the_migration_backfills_review_and_canonical_conflict_columns_from_import_metadata},
     * which writes and reads every one of them. Indexes have no behavioural witness,
     * so this guardrail retains just the index assertions.
     */
    #[Test]
    public function it_creates_normalized_review_and_canonical_conflict_indexes(): void
    {
        $this->assertTrue(Schema::hasIndex('church_services', 'church_services_review_state_index'));
        $this->assertTrue(Schema::hasIndex('church_services', 'church_services_canonical_conflict_state_index'));
    }

    #[Test]
    public function the_migration_backfills_review_and_canonical_conflict_columns_from_import_metadata(): void
    {
        $reviewer = User::factory()->create();

        $service = ChurchService::factory()->create([
            'needs_review' => true,
            'import_metadata' => [
                'manual_review' => [
                    'reviewed_at' => '2026-03-20T09:00:00+00:00',
                    'reviewed_by_user_id' => $reviewer->id,
                    'reopened_at' => '2026-03-21T09:00:00+00:00',
                    'reopened_by_source' => 'openlp',
                ],
                'canonical_conflict' => [
                    'detected_at' => '2026-03-21T09:30:00+00:00',
                    'incoming_source' => 'openlp',
                    'review_reopened' => true,
                    'reviewed_previously' => true,
                    'canonical_changed' => true,
                    'changes' => [['type' => 'updated_item']],
                    'conflicts' => [['type' => 'preserved_existing_song']],
                ],
            ],
        ]);

        DB::table('church_services')
            ->where('id', $service->id)
            ->update([
                'review_state' => ChurchServiceReviewState::NotReviewed->value,
                'manual_reviewed_at' => null,
                'manual_reviewed_by_user_id' => null,
                'manual_review_reopened_at' => null,
                'manual_review_reopened_by_source' => null,
                'canonical_conflict_state' => ChurchServiceCanonicalConflictState::NONE->value,
                'canonical_conflict_detected_at' => null,
                'canonical_conflict_incoming_source' => null,
                'canonical_conflict_reviewed_previously' => null,
                'canonical_conflict_canonical_changed' => null,
                'canonical_conflict_reason' => null,
            ]);

        $migration = require database_path('migrations/2026_03_22_120000_formalize_review_publication_state_and_ordering_invariants.php');
        $migration->up();

        $service->refresh();

        $this->assertSame(ChurchServiceReviewState::Reopened, $service->review_state);
        $this->assertSame($reviewer->id, $service->manual_reviewed_by_user_id);
        $this->assertSame('openlp', $service->manual_review_reopened_by_source);
        $this->assertSame(ChurchServiceCanonicalConflictState::REOPENED, $service->canonical_conflict_state);
        $this->assertTrue($service->canonical_conflict_reviewed_previously);
        $this->assertTrue($service->canonical_conflict_canonical_changed);
        $this->assertSame(
            ChurchServiceCanonicalConflictReason::CANONICAL_CHANGED_WITH_CONFLICTS,
            $service->canonical_conflict_reason
        );
    }
}
