<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchServiceItemAssertion;
use App\Models\ChurchServiceMergeProposal;
use App\Models\ChurchServiceReviewDecision;
use App\Models\ChurchServiceReviewSession;
use App\Models\ChurchServiceSourceRecord;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceEvidenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_the_normalized_evidence_and_review_schema(): void
    {
        $this->assertTrue(Schema::hasColumns('church_service_source_records', [
            'church_service_id',
            'source',
            'source_key',
            'revision_hash',
            'input_hash',
            'supersedes_id',
            'batch_hash',
            'processing_fingerprint',
            'service_content',
            'payload_complete',
            'captured_at',
            'created_by_user_id',
        ]));
        $this->assertTrue(Schema::hasColumns('church_service_item_assertions', [
            'source_record_id',
            'assertion_key',
            'source_position',
            'evidence_kind',
            'song_canonical_key',
            'normalized_scripture_key',
        ]));
        $this->assertTrue(Schema::hasTable('church_service_merge_proposals'));
        $this->assertTrue(Schema::hasTable('church_service_review_sessions'));
        $this->assertTrue(Schema::hasTable('church_service_review_decisions'));
        $this->assertTrue(Schema::hasColumns('church_services', [
            'canonical_revision',
            'canonical_hash',
            'reviewed_canonical_revision',
            'source_summary',
        ]));
        $this->assertTrue(Schema::hasColumns('church_service_items', [
            'canonical_identity',
            'occurrence_state',
            'manual_occurrence_decision',
        ]));
        $this->assertTrue(Schema::hasIndex(
            'church_service_source_records',
            'church_service_source_records_revision_unique',
        ));
        $this->assertTrue(Schema::hasIndex(
            'church_service_source_records',
            'church_service_source_records_service_source_index',
        ));
        $this->assertTrue(Schema::hasIndex(
            'church_service_item_assertions',
            'service_assertions_record_position_index',
        ));
        $this->assertTrue(Schema::hasIndex(
            'church_service_items',
            'church_service_items_canonical_identity_index',
        ));
    }

    #[Test]
    public function it_rejects_duplicate_source_revisions(): void
    {
        $record = ChurchServiceSourceRecord::factory()->create();

        $this->expectException(QueryException::class);

        ChurchServiceSourceRecord::factory()->create([
            'source' => $record->source,
            'source_key' => $record->source_key,
            'revision_hash' => $record->revision_hash,
        ]);
    }

    #[Test]
    public function model_defaults_casts_factories_and_relationships_match_the_schema(): void
    {
        $record = ChurchServiceSourceRecord::factory()->create([
            'source' => ChurchServiceSource::Livestream,
            'payload_complete' => false,
        ]);
        $assertion = ChurchServiceItemAssertion::factory()->create([
            'source_record_id' => $record,
            'evidence_kind' => ChurchServiceEvidenceKind::Observed,
            'confidence' => 0.875,
        ]);
        $proposal = ChurchServiceMergeProposal::factory()->create([
            'church_service_id' => $record->church_service_id,
            'trigger_source_record_id' => $record,
        ]);
        $session = ChurchServiceReviewSession::factory()->create([
            'church_service_id' => $record->church_service_id,
        ]);
        $decision = ChurchServiceReviewDecision::factory()->create([
            'review_session_id' => $session,
            'selected_assertion_id' => $assertion,
            'occurrence_decision' => ChurchServiceOccurrenceState::ManuallyConfirmed,
        ]);

        $this->assertSame(ChurchServiceSource::Livestream, $record->source);
        $this->assertFalse($record->payload_complete);
        $this->assertSame(ChurchServiceEvidenceKind::Observed, $assertion->evidence_kind);
        $this->assertSame(ChurchServiceProposalStatus::Pending, $proposal->status);
        $this->assertSame($record->id, $assertion->sourceRecord->id);
        $this->assertSame($record->church_service_id, $proposal->churchService->id);
        $this->assertSame($session->id, $decision->reviewSession->id);
        $this->assertSame(ChurchServiceOccurrenceState::ManuallyConfirmed, $decision->occurrence_decision);
        $this->assertSame(0, $record->churchService->canonical_revision);
    }

    #[Test]
    public function evidence_migrations_roll_back_and_reapply_cleanly(): void
    {
        $canonicalMigration = require database_path('migrations/2026_07_29_210107_add_canonical_columns_to_church_service_tables.php');
        $evidenceMigration = require database_path('migrations/2026_07_29_210105_create_church_service_evidence_tables.php');

        $canonicalMigration->down();
        $evidenceMigration->down();

        $this->assertFalse(Schema::hasTable('church_service_source_records'));
        $this->assertFalse(Schema::hasColumn('church_services', 'canonical_revision'));

        $evidenceMigration->up();
        $canonicalMigration->up();

        $this->assertTrue(Schema::hasTable('church_service_source_records'));
        $this->assertTrue(Schema::hasColumn('church_services', 'canonical_revision'));
        $this->assertSame(0, DB::table('church_services')->value('canonical_revision') ?? 0);
    }
}
