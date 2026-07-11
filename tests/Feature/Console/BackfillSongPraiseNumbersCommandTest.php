<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillSongPraiseNumbersCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_backfills_praise_numbers_from_title_suffixes_idempotently(): void
    {
        $numbered = Song::factory()->create(['title' => 'All Heaven Declares #477', 'praise_number' => null]);
        $letterVariant = Song::factory()->create(['title' => 'Crown Him #046A', 'praise_number' => null]);
        $plain = Song::factory()->create(['title' => 'Above The Voices', 'praise_number' => null]);
        $alreadySet = Song::factory()->create(['title' => 'Abide With Me #45', 'praise_number' => '45']);

        $this->artisan('songs:backfill-praise-numbers')
            ->expectsOutputToContain('2 updated, 1 already set, 1 titles without a number')
            ->assertExitCode(0);

        $this->assertSame('477', $numbered->refresh()->praise_number);
        $this->assertSame('046A', $letterVariant->refresh()->praise_number);
        $this->assertNull($plain->refresh()->praise_number);
        $this->assertSame('45', $alreadySet->refresh()->praise_number);

        $this->artisan('songs:backfill-praise-numbers')
            ->expectsOutputToContain('0 updated, 3 already set, 1 titles without a number')
            ->assertExitCode(0);
    }

    #[Test]
    public function dry_run_reports_without_writing(): void
    {
        $song = Song::factory()->create(['title' => 'All Heaven Declares #477', 'praise_number' => null]);

        $this->artisan('songs:backfill-praise-numbers', ['--dry-run' => true])
            ->expectsOutputToContain('(dry run): 1 updated')
            ->assertExitCode(0);

        $this->assertNull($song->refresh()->praise_number);
    }
}
