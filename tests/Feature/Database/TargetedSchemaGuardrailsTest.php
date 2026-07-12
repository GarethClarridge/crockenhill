<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TargetedSchemaGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_adds_targeted_indexes_and_removes_legacy_schema_artifacts(): void
    {
        $this->assertTrue(Schema::hasIndex('livestream_segments', 'livestream_segments_log_order_index'));
        $this->assertTrue(Schema::hasIndex('livestream_segments', 'livestream_segments_log_classification_time_index'));
        $this->assertTrue(Schema::hasIndex('media_processing_logs', 'media_processing_logs_dedup_key_unique'));
        $this->assertTrue(Schema::hasIndex('media_processing_logs', 'media_processing_logs_review_queue_index'));
        $this->assertTrue(Schema::hasIndex('speaker_samples', 'speaker_samples_profile_approved_index'));
        $this->assertTrue(Schema::hasIndex('speaker_samples', 'speaker_samples_profile_sermon_source_unique'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_log_type_order_index'));
        $this->assertTrue(Schema::hasIndex('service_sections', 'service_sections_publication_status_updated_at_index'));

        $this->assertFalse(Schema::hasTable('livestream_processing_logs'));
        $this->assertFalse(Schema::hasIndex('preachers', 'preachers_name_index'));
        $this->assertFalse(Schema::hasIndex('sermon_processing_steps', 'sermon_processing_steps_processing_id_step_index'));
    }

    #[Test]
    public function it_widens_segment_index_and_constrains_service_section_lifecycle_columns_to_enums(): void
    {
        $this->assertSame('smallint unsigned', $this->columnType('livestream_segments', 'segment_index'));
        $this->assertSame(
            "enum('identified')",
            $this->columnType('service_sections', 'status')
        );
        $this->assertSame(
            "enum('not_applicable','pending_approval','approved','rejected','published')",
            $this->columnType('service_sections', 'publication_status')
        );
    }

    private function columnType(string $table, string $column): string
    {
        /** @var string $columnType */
        $columnType = DB::table('information_schema.columns')
            ->whereRaw('table_schema = database()')
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->value('column_type');

        return $columnType;
    }
}
