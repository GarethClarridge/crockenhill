<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Relax the publication-link and publication-media check constraints on
 * service_sections so that song sections can be PUBLISHED without a
 * published_sermon_id or an extracted_audio_path.
 *
 * Song sections only produce a SongVideo (no Sermon), and only extract video
 * (no audio). The original constraints assumed all published sections are
 * sermon-based.
 */
return new class extends Migration
{
    private const PUBLICATION_LINK_CHECK = 'service_sections_publication_link_check';

    private const PUBLICATION_MEDIA_CHECK = 'service_sections_publication_media_check';

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        // Drop and recreate publication_link_check:
        // Old: published → must have published_sermon_id AND published_at
        // New: published → must have published_at; published_sermon_id is optional (null for songs)
        if ($this->constraintExists(self::PUBLICATION_LINK_CHECK)) {
            DB::statement('ALTER TABLE service_sections DROP CONSTRAINT '.self::PUBLICATION_LINK_CHECK);
        }

        DB::statement(
            'ALTER TABLE service_sections ADD CONSTRAINT '.self::PUBLICATION_LINK_CHECK.' CHECK ('.
            "((publication_status = 'published' AND published_at IS NOT NULL) ".
            "OR (publication_status <> 'published' AND published_sermon_id IS NULL AND published_at IS NULL))".
            ')'
        );

        // Drop and recreate publication_media_check:
        // Old: approved/published → must have video AND audio AND extracted_at
        // New: approved/published → must have video AND extracted_at; audio is optional (null for songs)
        if ($this->constraintExists(self::PUBLICATION_MEDIA_CHECK)) {
            DB::statement('ALTER TABLE service_sections DROP CONSTRAINT '.self::PUBLICATION_MEDIA_CHECK);
        }

        DB::statement(
            'ALTER TABLE service_sections ADD CONSTRAINT '.self::PUBLICATION_MEDIA_CHECK.' CHECK ('.
            "((publication_status IN ('approved', 'published') AND extracted_video_path IS NOT NULL AND extracted_at IS NOT NULL) ".
            "OR publication_status IN ('not_applicable', 'pending_approval', 'rejected'))".
            ')'
        );
    }

    public function down(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            return;
        }

        // Restore the original strict constraints.
        if ($this->constraintExists(self::PUBLICATION_LINK_CHECK)) {
            DB::statement('ALTER TABLE service_sections DROP CONSTRAINT '.self::PUBLICATION_LINK_CHECK);
        }

        DB::statement(
            'ALTER TABLE service_sections ADD CONSTRAINT '.self::PUBLICATION_LINK_CHECK.' CHECK ('.
            "((publication_status = 'published' AND published_sermon_id IS NOT NULL AND published_at IS NOT NULL) ".
            "OR (publication_status <> 'published' AND published_sermon_id IS NULL AND published_at IS NULL))".
            ')'
        );

        if ($this->constraintExists(self::PUBLICATION_MEDIA_CHECK)) {
            DB::statement('ALTER TABLE service_sections DROP CONSTRAINT '.self::PUBLICATION_MEDIA_CHECK);
        }

        DB::statement(
            'ALTER TABLE service_sections ADD CONSTRAINT '.self::PUBLICATION_MEDIA_CHECK.' CHECK ('.
            "((publication_status IN ('approved', 'published') AND extracted_video_path IS NOT NULL AND extracted_audio_path IS NOT NULL AND extracted_at IS NOT NULL) ".
            "OR publication_status IN ('not_applicable', 'pending_approval', 'rejected'))".
            ')'
        );
    }

    private function constraintExists(string $constraintName): bool
    {
        if (DB::getDriverName() === 'mysql') {
            return (int) DB::selectOne(
                'SELECT COUNT(*) AS cnt FROM information_schema.TABLE_CONSTRAINTS '.
                "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_sections' AND CONSTRAINT_NAME = ?",
                [$constraintName]
            )?->cnt > 0;
        }

        return (int) DB::selectOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.table_constraints '.
            "WHERE table_name = 'service_sections' AND constraint_name = ?",
            [$constraintName]
        )?->cnt > 0;
    }
};
