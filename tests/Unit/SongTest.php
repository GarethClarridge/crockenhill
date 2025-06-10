<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Crockenhill\Song;
use Crockenhill\ScriptureReference;
use Crockenhill\PlayDate; // Added PlayDate model
use Database\Factories\SongFactory;
use Database\Factories\ScriptureReferenceFactory;
use Database\Factories\PlayDateFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use Carbon\Carbon; // For date casting tests

class SongTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function testSongRelationships()
    {
        $song = Song::factory()->create();

        // Test scripture_reference() relationship (hasMany)
        $scriptureRef1 = ScriptureReference::factory()->forSong($song)->create();
        $scriptureRef2 = ScriptureReference::factory()->forSong($song)->create();

        $this->assertInstanceOf(EloquentCollection::class, $song->scripture_reference);
        $this->assertCount(2, $song->scripture_reference);
        $this->assertTrue($song->scripture_reference->contains($scriptureRef1));
        $this->assertTrue($song->scripture_reference->contains($scriptureRef2));

        // Test play_date() relationship (hasMany)
        // This assumes PlayDate model and DB table has 'song_id' column
        $playDate1 = PlayDate::factory()->forSong($song)->create();
        $playDate2 = PlayDate::factory()->forSong($song)->create();

        // Need to refresh the song instance to load the new play_date relations
        $song->refresh();

        $this->assertInstanceOf(EloquentCollection::class, $song->play_date);
        $this->assertCount(2, $song->play_date);
        $this->assertTrue($song->play_date->contains($playDate1));
        $this->assertTrue($song->play_date->contains($playDate2));
    }

    /**
     * @test
     */
    public function testSongAccessors()
    {
        // Test getFormattedTitleAttribute (assuming it returns the title directly for now)
        $songWithTitle = Song::factory()->create(['title' => 'Amazing Grace']);
        $this->assertEquals('Amazing Grace', $songWithTitle->formatted_title);
        // This test assumes an accessor like:
        // public function getFormattedTitleAttribute() { return $this->attributes['title']; }
        // Or more complex logic if formatting is involved.

        // Test getScriptureReferencesListAttribute
        $songWithRefs = Song::factory()->create();
        $ref1 = ScriptureReference::factory()->forSong($songWithRefs)->create(['reference_string' => 'John 3:16']);
        $ref2 = ScriptureReference::factory()->forSong($songWithRefs)->create(['reference_string' => 'Psalm 23']);

        // Refresh to ensure relationship is loaded if accessor queries it
        $songWithRefs->refresh();

        $expectedList = 'John 3:16, Psalm 23';
        $this->assertEquals($expectedList, $songWithRefs->scripture_references_list);
        // This test assumes an accessor like:
        // public function getScriptureReferencesListAttribute() {
        //     return $this->scripture_reference->pluck('reference_string')->implode(', ');
        // }

        $songWithoutRefs = Song::factory()->create();
        $this->assertEquals('', $songWithoutRefs->scripture_references_list);
    }

    /**
     * @test
     */
    public function testSongMutatorsAndCasts()
    {
        // Test last_played_at casting to Carbon instance
        // This assumes 'last_played_at' column exists and is cast to datetime in Song model
        $now = Carbon::now();
        $songWithPlayDate = Song::factory()->create(['last_played_at' => $now]);
        $this->assertInstanceOf(Carbon::class, $songWithPlayDate->last_played_at);
        $this->assertEquals($now->toDateTimeString(), $songWithPlayDate->last_played_at->toDateTimeString());

        $songWithoutPlayDate = Song::factory()->create(['last_played_at' => null]);
        $this->assertNull($songWithoutPlayDate->last_played_at);

        // Test lyrics attribute (assuming it's a text field, no specific cast needed beyond string)
        // If lyrics were JSON, the test would be:
        // $lyricsArray = ['verse1' => 'Line 1', 'chorus' => 'Chorus line'];
        // $songWithJsonLyrics = Song::factory()->create(['lyrics' => json_encode($lyricsArray)]);
        // $this->assertIsArray($songWithJsonLyrics->lyrics);
        // $this->assertEquals($lyricsArray, $songWithJsonLyrics->lyrics);
        $lyricsText = "Verse 1...\nChorus...";
        $songWithTextLyrics = Song::factory()->withLyrics($lyricsText)->create();
        $this->assertEquals($lyricsText, $songWithTextLyrics->lyrics);
    }

    /**
     * @test
     */
    public function testSongScopes()
    {
        // Test withLyrics() scope
        $songWithLyrics = Song::factory()->withLyrics('Some lyrics...')->create();
        $songWithoutLyrics = Song::factory()->withoutLyrics()->create();

        $songsFound = Song::withLyrics()->get();
        $this->assertTrue($songsFound->contains($songWithLyrics));
        $this->assertFalse($songsFound->contains($songWithoutLyrics));
        // Assumes scope: public function scopeWithLyrics($query) { return $query->whereNotNull('lyrics')->where('lyrics', '!=', ''); }

        // Test orderByTitle() scope
        $songB = Song::factory()->create(['title' => 'Song B']);
        $songA = Song::factory()->create(['title' => 'Song A']);
        $songC = Song::factory()->create(['title' => 'Song C']);

        $sortedSongs = Song::orderByTitle()->get();
        $this->assertEquals('Song A', $sortedSongs->get(0)->title);
        $this->assertEquals('Song B', $sortedSongs->get(1)->title);
        $this->assertEquals('Song C', $sortedSongs->get(2)->title);
        // Assumes scope: public function scopeOrderByTitle($query) { return $query->orderBy('title', 'asc'); }

        // Test matchingScripture(string $reference) scope
        $targetReference = 'John 3:16';
        $songWithMatchingRef = Song::factory()->create();
        ScriptureReference::factory()->forSong($songWithMatchingRef)->withReference($targetReference)->create();

        $songWithDifferentRef = Song::factory()->create();
        ScriptureReference::factory()->forSong($songWithDifferentRef)->withReference('Psalm 23')->create();

        $songWithNoRef = Song::factory()->create();

        $matchingSongs = Song::matchingScripture($targetReference)->get();
        $this->assertCount(1, $matchingSongs);
        $this->assertTrue($matchingSongs->contains($songWithMatchingRef));
        $this->assertFalse($matchingSongs->contains($songWithDifferentRef));
        $this->assertFalse($matchingSongs->contains($songWithNoRef));
        // Assumes scope: public function scopeMatchingScripture($query, string $reference) {
        //     return $query->whereHas('scripture_reference', function ($q) use ($reference) {
        //         $q->where('reference_string', $reference);
        //     });
        // }
    }

    /**
     * @test
     */
    public function testCustomSongMethods()
    {
        // Test hasScriptureReference(string $reference)
        $targetReference = 'John 3:16';
        $otherReference = 'Psalm 23';

        $songWithTargetRef = Song::factory()->create();
        ScriptureReference::factory()->forSong($songWithTargetRef)->withReference($targetReference)->create();

        $songWithOtherRef = Song::factory()->create();
        ScriptureReference::factory()->forSong($songWithOtherRef)->withReference($otherReference)->create();

        $songWithNoRefs = Song::factory()->create();

        $this->assertTrue($songWithTargetRef->hasScriptureReference($targetReference));
        $this->assertFalse($songWithTargetRef->hasScriptureReference($otherReference));
        $this->assertFalse($songWithOtherRef->hasScriptureReference($targetReference));
        $this->assertFalse($songWithNoRefs->hasScriptureReference($targetReference));

        // This test assumes a method like:
        // public function hasScriptureReference(string $reference): bool {
        //     return $this->scripture_reference()->where('reference_string', $reference)->exists();
        // }
    }
}
