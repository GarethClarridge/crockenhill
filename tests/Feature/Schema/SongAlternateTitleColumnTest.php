<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Models\Song;
use App\Services\Song\SongTitleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `songs` carried two alternate-title columns until 2026-09-18. Only
 * `alternate_title` was ever written — by the OpenLP sync — and only it is
 * indexed by the resolver; `alternative_title` was a legacy twin populated on
 * 0 of 1,160 live rows.
 */
class SongAlternateTitleColumnTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_legacy_alternative_title_column_is_gone(): void
    {
        $this->assertFalse(
            Schema::hasColumn('songs', 'alternative_title'),
            'The legacy alternative_title twin should have been dropped.',
        );

        $this->assertTrue(
            Schema::hasColumn('songs', 'alternate_title'),
            'alternate_title is the column the OpenLP sync writes and the resolver indexes.',
        );
    }

    /**
     * The reason the surviving column matters: a hymn the catalogue files under
     * its first line is unreachable by the name the church sings it by, unless
     * that name is in `alternate_title`. Song 712 is the corpus's own example —
     * "O Lord My God #190" is "How Great Thou Art".
     */
    #[Test]
    public function an_alternate_title_resolves_a_hymn_filed_under_its_first_line(): void
    {
        $song = Song::factory()->create([
            'title' => 'O Lord My God #190',
            'canonical_key' => 'o lord my god #190',
            'praise_number' => '190',
            'alternate_title' => null,
        ]);

        $this->assertNull(
            SongTitleResolver::fromDatabase()->resolve('How Great Thou Art'),
            'Without the alternate title the refrain name should not resolve.',
        );

        $song->update(['alternate_title' => 'How Great Thou Art']);

        $match = SongTitleResolver::fromDatabase()->resolve('How Great Thou Art');

        $this->assertNotNull($match);
        $this->assertSame($song->id, $match->songId);
    }

    /**
     * And why the 686 mechanical values found on 2026-09-18 were waste: the
     * resolver already derives the number-stripped form from the title itself,
     * so storing it in the one alternate-title slot buys no new lookup key.
     */
    #[Test]
    public function the_number_stripped_title_resolves_without_any_alternate_title(): void
    {
        $song = Song::factory()->create([
            'title' => 'O Lord My God #190',
            'canonical_key' => 'o lord my god #190',
            'praise_number' => '190',
            'alternate_title' => null,
        ]);

        $match = SongTitleResolver::fromDatabase()->resolve('O Lord My God');

        $this->assertNotNull($match, 'The number-stripped title should resolve from the title alone.');
        $this->assertSame($song->id, $match->songId);
    }
}
