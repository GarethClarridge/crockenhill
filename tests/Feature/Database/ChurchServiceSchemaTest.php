<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\ChurchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_expands_the_collapsed_review_state_without_dropping_legacy_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('church_services', 'review_reason'));
        $this->assertTrue(Schema::hasIndex('church_services', 'church_services_review_state_index'));
        $this->assertTrue(Schema::hasIndex('church_services', 'church_services_canonical_conflict_state_index'));
    }

    #[Test]
    public function it_backfills_outstanding_canonical_conflicts_to_human_readable_reasons(): void
    {
        $changed = ChurchService::factory()->create([
            'needs_review' => true,
            'canonical_conflict_state' => 'reopened',
            'canonical_conflict_detected_at' => now(),
            'canonical_conflict_incoming_source' => 'openlp',
            'canonical_conflict_reviewed_previously' => true,
            'canonical_conflict_canonical_changed' => true,
            'canonical_conflict_reason' => 'canonical_changed',
            'import_metadata' => [
                'canonical_conflict' => ['incoming_source' => 'openlp'],
                'canonical_conflict_history' => [['incoming_source' => 'openlp']],
            ],
        ]);
        $conflicted = ChurchService::factory()->create([
            'needs_review' => true,
            'canonical_conflict_state' => 'detected',
            'canonical_conflict_detected_at' => now(),
            'canonical_conflict_incoming_source' => 'email',
            'canonical_conflict_reviewed_previously' => false,
            'canonical_conflict_canonical_changed' => false,
            'canonical_conflict_reason' => 'conflicts_only',
        ]);

        $migration = require database_path('migrations/2026_07_20_124837_backfill_church_service_review_reasons.php');
        $migration->up();

        $this->assertSame('Service items changed after manual review.', $changed->fresh()->review_reason);
        $this->assertSame('Incoming service data conflicted with existing items.', $conflicted->fresh()->review_reason);
        $this->assertArrayNotHasKey('canonical_conflict', $changed->fresh()->import_metadata->toArray());
        $this->assertCount(1, $changed->fresh()->import_metadata['canonical_conflict_history']);
    }
}
