<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
