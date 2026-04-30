<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonContentType;
use App\Models\Sermon;
use App\Models\SermonScriptureFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SyncSermonScriptureFiltersCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_backfills_missing_rows(): void
    {
        $sermon = $this->createUnindexedSermon(['reference' => 'John 3:16']);

        $this->artisan('sermons:sync-scripture-filters')
            ->expectsOutputToContain('Indexed: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('sermon_scripture_filters', [
            'sermon_id' => $sermon->id,
            'bible_book' => 'John',
            'bible_chapter' => 3,
        ]);
    }

    #[Test]
    public function it_repairs_stale_rows(): void
    {
        $sermon = $this->createUnindexedSermon(['reference' => 'John 3:16']);
        SermonScriptureFilter::factory()->for($sermon)->forPassage('Genesis', 1)->create();

        $this->artisan('sermons:sync-scripture-filters')
            ->expectsOutputToContain('Indexed: 1')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sermon_scripture_filters', [
            'sermon_id' => $sermon->id,
            'bible_book' => 'Genesis',
            'bible_chapter' => 1,
        ]);

        $this->assertDatabaseHas('sermon_scripture_filters', [
            'sermon_id' => $sermon->id,
            'bible_book' => 'John',
            'bible_chapter' => 3,
        ]);
    }

    #[Test]
    public function it_reports_counts_accurately(): void
    {
        $this->createUnindexedSermon(['reference' => 'John 3:16']);
        $this->createUnindexedSermon(['reference' => null]);
        $this->createUnindexedSermon(['reference' => 'totally invalid reference']);
        $alreadyIndexed = $this->createUnindexedSermon(['reference' => 'Romans 8:1']);
        SermonScriptureFilter::factory()->for($alreadyIndexed)->forPassage('Romans', 8)->create();

        // When --only-missing is used, already-indexed sermons are filtered out by the query
        $this->artisan('sermons:sync-scripture-filters', ['--only-missing' => true])
            ->expectsOutputToContain('Indexed: 1, Cleared: 1, Unparseable: 1')
            ->assertSuccessful();
    }

    #[Test]
    public function dry_run_does_not_write_any_rows(): void
    {
        $this->createUnindexedSermon(['reference' => 'John 3:16']);

        $this->artisan('sermons:sync-scripture-filters', ['--dry-run' => true])
            ->expectsOutputToContain('(dry run)')
            ->assertSuccessful();

        $this->assertDatabaseCount('sermon_scripture_filters', 0);
    }

    #[Test]
    public function sermon_option_scopes_the_sync_to_one_record(): void
    {
        $target = $this->createUnindexedSermon(['reference' => 'John 3:16']);
        $other = $this->createUnindexedSermon(['reference' => 'Romans 8:1']);

        $this->artisan('sermons:sync-scripture-filters', ['--sermon' => $target->id])
            ->expectsOutputToContain('Syncing scripture filters for 1 sermon')
            ->assertSuccessful();

        $this->assertDatabaseHas('sermon_scripture_filters', [
            'sermon_id' => $target->id,
            'bible_book' => 'John',
            'bible_chapter' => 3,
        ]);

        $this->assertDatabaseMissing('sermon_scripture_filters', [
            'sermon_id' => $other->id,
        ]);
    }

    #[Test]
    public function non_public_sermons_are_cleared(): void
    {
        $sermon = $this->createUnindexedSermon([
            'content_type' => SermonContentType::ChildrensTalk,
            'reference' => 'John 3:16',
        ]);
        SermonScriptureFilter::factory()->for($sermon)->forPassage('John', 3)->create();

        $this->artisan('sermons:sync-scripture-filters')
            ->expectsOutputToContain('Cleared: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('sermon_scripture_filters', 0);
    }

    private function createUnindexedSermon(array $attributes): Sermon
    {
        /** @var Sermon $sermon */
        $sermon = Sermon::withoutEvents(fn (): Sermon => Sermon::factory()->create($attributes));

        return $sermon;
    }
}
