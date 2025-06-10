<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Crockenhill\User;
use Crockenhill\Song;
use Crockenhill\ScriptureReference;
use Database\Factories\UserFactory;
use Database\Factories\SongFactory;
use Database\Factories\ScriptureReferenceFactory;

class SongControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = User::factory()->admin()->create();
        $this->regularUser = User::factory()->create(['is_admin_for_test' => false]);
    }

    // 1. Authentication/Authorization Tests
    public function testGuestsCannotAccessSongManagement()
    {
        $song = Song::factory()->create();

        $this->get('/songs')->assertRedirect('/login');
        $this->get('/songs/create')->assertRedirect('/login');
        $this->post('/songs', [])->assertRedirect('/login');
        $this->get("/songs/{$song->id}/edit")->assertRedirect('/login');
        $this->put("/songs/{$song->id}", [])->assertRedirect('/login');
        $this->delete("/songs/{$song->id}")->assertRedirect('/login');
        // Assuming /songs/{id} is public, so not testing redirect for show
    }

    public function testRegularUsersHaveRestrictedAccess()
    {
        // This test depends on specific authorization logic.
        // Assuming regular users can view index and show, but not CUD operations.
        $this->actingAs($this->regularUser);
        $song = Song::factory()->create();

        $this->get('/songs')->assertOk(); // Regular users can see the list
        $this->get("/songs/{$song->id}")->assertOk(); // Regular users can see a song

        $this->get('/songs/create')->assertForbidden(); // Or assertStatus(403)
        $this->post('/songs', [
            'title' => 'New Song by Regular User',
            'lyrics' => 'Some lyrics'
        ])->assertForbidden();

        $this->get("/songs/{$song->id}/edit")->assertForbidden();
        $this->put("/songs/{$song->id}", ['title' => 'Updated Title'])->assertForbidden();
        $this->delete("/songs/{$song->id}")->assertForbidden();
    }

    // 2. testSongIndexPageLoads
    /** @test */
    public function song_index_page_loads_for_admin_users()
    {
        Song::factory()->count(3)->create();
        $response = $this->actingAs($this->adminUser)->get('/songs');
        $response->assertOk();
        $response->assertViewIs('songs.index'); // Assuming a view name
        // $response->assertSee(Song::first()->title); // Check if song titles are displayed
    }

    // 3. testSongCreatePageLoads
    /** @test */
    public function song_create_page_loads_for_admin_users()
    {
        $response = $this->actingAs($this->adminUser)->get('/songs/create');
        $response->assertOk();
        $response->assertViewIs('songs.create'); // Assuming a view name
        // $response->assertSee('Create Song'); // Check for form elements
    }

    // 4. testStoreNewSong
    /** @test */
    public function admin_user_can_store_new_song()
    {
        $songData = [
            'title' => 'A Brand New Song',
            'lyrics' => 'Here are the lyrics...',
            'copyright' => '© 2023 Test Music',
            'ccli_number' => '1234567',
            'scripture_references' => ['John 3:16', 'Psalm 23:1-3'], // Assuming this is how refs are passed
        ];

        $response = $this->actingAs($this->adminUser)->post('/songs', $songData);

        $this->assertDatabaseHas('songs', ['title' => 'A Brand New Song']);
        $newSong = Song::where('title', 'A Brand New Song')->first();
        $this->assertNotNull($newSong);

        // Check scripture references
        $this->assertDatabaseHas('scripture_references', ['song_id' => $newSong->id, 'reference_string' => 'John 3:16']);
        $this->assertDatabaseHas('scripture_references', ['song_id' => $newSong->id, 'reference_string' => 'Psalm 23:1-3']);

        $response->assertRedirect('/songs'); // Or /songs/{id}
        $response->assertSessionHas('success'); // Or similar flash message
    }

    /** @test */
    public function store_song_fails_with_invalid_data()
    {
        $response = $this->actingAs($this->adminUser)->post('/songs', [
            'title' => '', // Title is required
            'lyrics' => 'Some lyrics',
        ]);
        $response->assertSessionHasErrors('title');
        $this->assertDatabaseMissing('songs', ['lyrics' => 'Some lyrics']);
    }

    // 5. testSongShowPageLoads
    /** @test */
    public function song_show_page_loads_for_everyone()
    {
        $song = Song::factory()->create();
        ScriptureReference::factory()->forSong($song)->create(['reference_string' => 'Gen 1:1']);

        $response = $this->get("/songs/{$song->id}"); // Assuming ID is used in route
        $response->assertOk();
        $response->assertViewIs('songs.show'); // Assuming view
        $response->assertSee($song->title);
        $response->assertSee($song->lyrics);
        $response->assertSee('Gen 1:1');
    }

    /** @test */
    public function song_show_page_returns_404_for_non_existent_song()
    {
        $this->get('/songs/9999')->assertNotFound();
    }

    // 6. testSongEditPageLoads
    /** @test */
    public function song_edit_page_loads_for_admin_users()
    {
        $song = Song::factory()->create();
        $response = $this->actingAs($this->adminUser)->get("/songs/{$song->id}/edit");
        $response->assertOk();
        $response->assertViewIs('songs.edit'); // Assuming view
        $response->assertSee($song->title); // Check if form is pre-filled
    }

    /** @test */
    public function song_edit_page_returns_404_for_non_existent_song_for_admin()
    {
        $this->actingAs($this->adminUser)->get('/songs/9999/edit')->assertNotFound();
    }

    // 7. testUpdateExistingSong
    /** @test */
    public function admin_user_can_update_existing_song()
    {
        $song = Song::factory()->create();
        $updateData = [
            'title' => 'Updated Song Title',
            'lyrics' => 'Updated lyrics here.',
            'copyright' => $song->copyright, // Keep some old data
            'scripture_references' => ['Romans 8:28', '1 Corinthians 13'], // New set of refs
        ];

        $response = $this->actingAs($this->adminUser)->put("/songs/{$song->id}", $updateData);

        $this->assertDatabaseHas('songs', ['id' => $song->id, 'title' => 'Updated Song Title']);
        $updatedSong = Song::find($song->id);
        $this->assertEquals('Updated lyrics here.', $updatedSong->lyrics);

        // Check scripture references update (assuming old ones are removed or handled)
        // This requires specific logic in the controller: delete old, create new.
        $this->assertDatabaseHas('scripture_references', ['song_id' => $song->id, 'reference_string' => 'Romans 8:28']);
        $this->assertDatabaseMissing('scripture_references', ['song_id' => $song->id, 'reference_string' => 'John 3:16']); // Assuming an old ref

        $response->assertRedirect('/songs'); // Or /songs/{id}
        $response->assertSessionHas('success');
    }

    /** @test */
    public function update_song_fails_with_invalid_data()
    {
        $song = Song::factory()->create();
        $response = $this->actingAs($this->adminUser)->put("/songs/{$song->id}", [
            'title' => '', // Title is required
        ]);
        $response->assertSessionHasErrors('title');
        $this->assertNotEquals('', Song::find($song->id)->title); // Title should not have changed
    }

    // 8. testDestroySong
    /** @test */
    public function admin_user_can_destroy_song()
    {
        $song = Song::factory()->create();
        // Also create scripture references to test cascading delete or manual delete in controller
        ScriptureReference::factory()->count(2)->forSong($song)->create();

        $response = $this->actingAs($this->adminUser)->delete("/songs/{$song->id}");

        $this->assertDatabaseMissing('songs', ['id' => $song->id]);
        // Check if related scripture references are also deleted
        $this->assertDatabaseMissing('scripture_references', ['song_id' => $song->id]);

        $response->assertRedirect('/songs');
        $response->assertSessionHas('success');
    }

    /** @test */
    public function destroy_non_existent_song_returns_404_for_admin()
    {
        $this->actingAs($this->adminUser)->delete('/songs/9999')->assertNotFound();
    }

    // 9. testSongSearch (Placeholder - depends on actual search implementation)
    /** @test */
    public function song_search_returns_relevant_results()
    {
        $song1 = Song::factory()->create(['title' => 'Amazing Grace']);
        $song2 = Song::factory()->create(['title' => 'Grace Alone']);
        $song3 = Song::factory()->create(['title' => 'Mighty Fortress']);

        // Assuming search route is /songs/search?q=Grace
        $response = $this->actingAs($this->adminUser)->get('/songs/search?q=Grace');
        $response->assertOk();
        // $response->assertSee($song1->title);
        // $response->assertSee($song2->title);
        // $response->assertDontSee($song3->title);
        $this->markTestIncomplete('Search functionality test depends on specific implementation details.');
    }

    // 10. testScriptureReferenceLinking is partially covered in store/update tests.
    // Additional specific tests can be added if there's a dedicated UI for managing these links
    // or more complex logic.

    /** @test */
    public function filtering_songs_by_scripture_reference()
    {
        $refString = 'John 3:16';
        $refSlug = \Illuminate\Support\Str::slug($refString); // Assuming slugified ref for route

        $song1 = Song::factory()->create();
        ScriptureReference::factory()->forSong($song1)->create(['reference_string' => $refString]);

        $song2 = Song::factory()->create();
        ScriptureReference::factory()->forSong($song2)->create(['reference_string' => 'Psalm 23']);

        // Assuming a route like /songs/scripture/john-3-16
        $response = $this->get("/songs/scripture/{$refSlug}");
        $response->assertOk();
        // $response->assertSee($song1->title);
        // $response->assertDontSee($song2->title);
        $this->markTestIncomplete('Filtering by scripture reference test depends on specific route and controller logic.');
    }
}
