<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\ScripturePassage;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonIdentityAuthoritySchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_migration_backfills_canonical_sermon_identity_links_and_documents_cache_columns(): void
    {
        config()->set('services.api_bible.default_bible_id', 'de4e12af7f28f599-02');

        $preacher = Preacher::factory()->create(['name' => 'John Smith']);
        PreacherAlias::query()->create([
            'preacher_id' => $preacher->id,
            'alias' => 'j smith',
        ]);

        $passage = ScripturePassage::factory()->create([
            'bible_id' => 'de4e12af7f28f599-02',
            'normalized_reference' => 'John 3:16',
            'display_reference' => 'John 3:16',
        ]);

        $aliasBackfill = Sermon::factory()->create([
            'preacher' => 'Placeholder',
            'preacher_id' => null,
            'reference' => null,
            'scripture_passage_id' => null,
        ]);

        $cacheRepair = Sermon::factory()->create([
            'preacher' => $preacher->name,
            'preacher_id' => $preacher->id,
            'reference' => $passage->display_reference,
            'scripture_passage_id' => $passage->id,
        ]);

        $scriptureBackfill = Sermon::factory()->create([
            'preacher' => 'Placeholder',
            'preacher_id' => null,
            'reference' => 'Placeholder',
            'scripture_passage_id' => null,
        ]);

        DB::table('sermons')->where('id', $aliasBackfill->id)->update([
            'preacher' => 'j smith',
            'preacher_id' => null,
        ]);

        DB::table('sermons')->where('id', $cacheRepair->id)->update([
            'preacher' => 'Stale Preacher Cache',
            'reference' => 'Stale Reference Cache',
        ]);

        DB::table('sermons')->where('id', $scriptureBackfill->id)->update([
            'reference' => 'John 3:16',
            'scripture_passage_id' => null,
        ]);

        $migration = require database_path('migrations/2026_03_22_213105_formalize_sermon_identity_authority.php');
        $migration->up();

        $this->assertSame(
            $preacher->id,
            DB::table('sermons')->where('id', $aliasBackfill->id)->value('preacher_id')
        );
        $this->assertSame(
            $preacher->name,
            DB::table('sermons')->where('id', $aliasBackfill->id)->value('preacher')
        );
        $this->assertSame(
            $preacher->name,
            DB::table('sermons')->where('id', $cacheRepair->id)->value('preacher')
        );
        $this->assertSame(
            $passage->display_reference,
            DB::table('sermons')->where('id', $cacheRepair->id)->value('reference')
        );
        $this->assertSame(
            $passage->id,
            DB::table('sermons')->where('id', $scriptureBackfill->id)->value('scripture_passage_id')
        );

        $columnComments = collect(DB::select(
            'SELECT COLUMN_NAME, COLUMN_COMMENT
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name IN (?, ?, ?, ?)',
            ['sermons', 'preacher', 'preacher_id', 'reference', 'scripture_passage_id']
        ))->mapWithKeys(fn (object $column): array => [$column->COLUMN_NAME => $column->COLUMN_COMMENT]);

        $this->assertStringContainsString('cache', strtolower((string) $columnComments['preacher']));
        $this->assertStringContainsString('canonical', strtolower((string) $columnComments['preacher_id']));
        $this->assertStringContainsString('cache', strtolower((string) $columnComments['reference']));
        $this->assertStringContainsString('canonical', strtolower((string) $columnComments['scripture_passage_id']));
    }

    #[Test]
    public function the_migration_leaves_blank_preacher_cache_values_unlinked_and_down_clears_column_comments(): void
    {
        config()->set('services.api_bible.default_bible_id', 'de4e12af7f28f599-02');

        $sermon = Sermon::factory()->create([
            'preacher' => 'Placeholder',
            'preacher_id' => null,
        ]);

        DB::statement('ALTER TABLE sermons ALTER CHECK sermons_preacher_format_check NOT ENFORCED');
        DB::table('sermons')->where('id', $sermon->id)->update([
            'preacher' => '',
            'preacher_id' => null,
        ]);

        $migration = require database_path('migrations/2026_03_22_213105_formalize_sermon_identity_authority.php');
        $migration->up();

        $this->assertSame('', DB::table('sermons')->where('id', $sermon->id)->value('preacher'));
        $this->assertNull(DB::table('sermons')->where('id', $sermon->id)->value('preacher_id'));

        $migration->down();

        $columnComments = collect(DB::select(
            'SELECT COLUMN_NAME, COLUMN_COMMENT
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name IN (?, ?, ?, ?)',
            ['sermons', 'preacher', 'preacher_id', 'reference', 'scripture_passage_id']
        ))->mapWithKeys(fn (object $column): array => [$column->COLUMN_NAME => $column->COLUMN_COMMENT]);

        $this->assertSame('', $columnComments['preacher']);
        $this->assertSame('', $columnComments['preacher_id']);
        $this->assertSame('', $columnComments['reference']);
        $this->assertSame('', $columnComments['scripture_passage_id']);
    }
}
