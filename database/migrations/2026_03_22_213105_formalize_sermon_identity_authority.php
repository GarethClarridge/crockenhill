<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREACHER_CACHE_COMMENT = 'Denormalized preacher display cache. preacher_id is the canonical preacher identity for the published sermon.';

    private const PREACHER_ID_COMMENT = 'Canonical preacher identity for the published sermon. The preacher text column is a synchronized cache.';

    private const REFERENCE_CACHE_COMMENT = 'Denormalized scripture reference cache for display/search compatibility. scripture_passage_id is the canonical normalized identity when present.';

    private const SCRIPTURE_PASSAGE_ID_COMMENT = 'Canonical normalized scripture identity for the published sermon. The reference text column is a synchronized cache.';

    public function up(): void
    {
        if (! Schema::hasTable('sermons')) {
            return;
        }

        DB::transaction(function (): void {
            $this->backfillPreacherIdentity();
            $this->backfillScriptureIdentity();
        });

        $this->applyColumnComments();
    }

    public function down(): void
    {
        if (! Schema::hasTable('sermons')) {
            return;
        }

        DB::statement(
            "ALTER TABLE sermons MODIFY preacher VARCHAR(255) NOT NULL DEFAULT 'Mark Drury' COMMENT ''"
        );
        DB::statement(
            "ALTER TABLE sermons MODIFY preacher_id BIGINT UNSIGNED NULL COMMENT ''"
        );
        DB::statement(
            "ALTER TABLE sermons MODIFY reference VARCHAR(255) NULL COMMENT ''"
        );
        DB::statement(
            "ALTER TABLE sermons MODIFY scripture_passage_id BIGINT UNSIGNED NULL COMMENT ''"
        );
    }

    private function backfillPreacherIdentity(): void
    {
        DB::statement(
            "UPDATE sermons s
            JOIN preacher_aliases pa ON pa.alias = LOWER(TRIM(s.preacher))
            JOIN preachers p ON p.id = pa.preacher_id
            SET s.preacher_id = p.id,
                s.preacher = p.name
            WHERE s.preacher_id IS NULL
              AND s.preacher IS NOT NULL
              AND TRIM(s.preacher) <> ''"
        );

        DB::statement(
            "UPDATE sermons s
            JOIN preachers p ON LOWER(p.name) = LOWER(TRIM(s.preacher))
            SET s.preacher_id = p.id,
                s.preacher = p.name
            WHERE s.preacher_id IS NULL
              AND s.preacher IS NOT NULL
              AND TRIM(s.preacher) <> ''"
        );

        DB::statement(
            "UPDATE sermons s
            JOIN preachers p ON p.id = s.preacher_id
            SET s.preacher = p.name
            WHERE s.preacher_id IS NOT NULL
              AND COALESCE(s.preacher, '') <> p.name"
        );
    }

    private function backfillScriptureIdentity(): void
    {
        DB::statement(
            "UPDATE sermons s
            JOIN scripture_passages sp ON sp.id = s.scripture_passage_id
            SET s.reference = COALESCE(NULLIF(sp.display_reference, ''), sp.normalized_reference)
            WHERE s.scripture_passage_id IS NOT NULL
              AND COALESCE(s.reference, '') <> COALESCE(NULLIF(sp.display_reference, ''), sp.normalized_reference)"
        );

        $bibleId = (string) config('services.api_bible.default_bible_id', '');
        if ($bibleId === '') {
            return;
        }

        DB::statement(
            "UPDATE sermons s
            JOIN scripture_passages sp
              ON sp.bible_id = ?
             AND sp.normalized_reference = TRIM(s.reference)
            SET s.scripture_passage_id = sp.id,
                s.reference = COALESCE(NULLIF(sp.display_reference, ''), sp.normalized_reference)
            WHERE s.scripture_passage_id IS NULL
              AND s.reference IS NOT NULL
              AND TRIM(s.reference) <> ''",
            [$bibleId]
        );

        DB::statement(
            "UPDATE sermons s
            JOIN scripture_passages sp
              ON sp.bible_id = ?
             AND sp.display_reference = TRIM(s.reference)
            SET s.scripture_passage_id = sp.id,
                s.reference = COALESCE(NULLIF(sp.display_reference, ''), sp.normalized_reference)
            WHERE s.scripture_passage_id IS NULL
              AND s.reference IS NOT NULL
              AND TRIM(s.reference) <> ''",
            [$bibleId]
        );
    }

    private function applyColumnComments(): void
    {
        DB::statement(sprintf(
            "ALTER TABLE sermons MODIFY preacher VARCHAR(255) NOT NULL DEFAULT 'Mark Drury' COMMENT %s",
            $this->quote(self::PREACHER_CACHE_COMMENT)
        ));

        DB::statement(sprintf(
            'ALTER TABLE sermons MODIFY preacher_id BIGINT UNSIGNED NULL COMMENT %s',
            $this->quote(self::PREACHER_ID_COMMENT)
        ));

        DB::statement(sprintf(
            'ALTER TABLE sermons MODIFY reference VARCHAR(255) NULL COMMENT %s',
            $this->quote(self::REFERENCE_CACHE_COMMENT)
        ));

        DB::statement(sprintf(
            'ALTER TABLE sermons MODIFY scripture_passage_id BIGINT UNSIGNED NULL COMMENT %s',
            $this->quote(self::SCRIPTURE_PASSAGE_ID_COMMENT)
        ));
    }

    private function quote(string $value): string
    {
        return DB::getPdo()->quote($value);
    }
};
