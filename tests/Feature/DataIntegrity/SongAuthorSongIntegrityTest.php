<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongAuthorSongIntegrityTest extends TestCase
{
    #[Test]
    public function it_has_indexes_on_song_id_and_song_author_id(): void
    {
        $this->assertTrue(
            Schema::hasIndex('song_author_song', 'song_author_song_song_id_index'),
            'The song_author_song table is missing the index on song_id'
        );

        $this->assertTrue(
            Schema::hasIndex('song_author_song', 'song_author_song_song_author_id_index'),
            'The song_author_song table is missing the index on song_author_id'
        );
    }
}
