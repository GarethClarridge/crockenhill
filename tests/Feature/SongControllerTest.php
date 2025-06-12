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
use PHPUnit\Framework\Attributes\Test;

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

        $this->get('/church/members/songs')->assertOk(); // Regular users can see the list
        $this->get("/church/members/songs/{$song->id}")->assertOk(); // Regular users can see a song

        $this->get('/church/members/songs/create')->assertForbidden(); // Or assertStatus(403)
        $this->post('/church/members/songs', [
            'title' => 'New Song by Regular User',
            'lyrics' => 'Some lyrics'
        ])->assertForbidden();

        $this->get("/church/members/songs/{$song->id}/edit")->assertForbidden();
        $this->put("/church/members/songs/{$song->id}", ['title' => 'Updated Title'])->assertForbidden();
        $this->delete("/church/members/songs/{$song->id}")->assertForbidden();
    }

    // 2. testSongIndexPageLoads
    #[Test]
    public function song_index_page_loads_for_admin_users()
    {
        Song::factory()->count(3)->create();
        $response = $this->actingAs($this->adminUser)->get('/church/members/songs');
        $response->assertOk();
        $response->assertViewIs('songs.index');
    }

    // 3. testSongCreatePageLoads
    #[Test]
    public function song_create_page_loads_for_admin_users()
    {
        $response = $this->actingAs($this->adminUser)->get('/church/members/songs/create');
        $response->assertOk();
        $response->assertViewIs('songs.create');
    }

    // 4. testStoreNewSong
    #[Test]
    public function admin_user_can_store_new_song()
    {
        $songData = [
            'title' => 'A Brand New Song',
            'lyrics' => 'Here are the lyrics...',
            'copyright' => '© 2023 Test Music',
            'ccli_number' => '1234567',
            'scripture_references' => ['John 3:16', 'Psalm 23:1-3'],
        ];

        $response = $this->actingAs($this->adminUser)->post('/church/members/songs', $songData);

        $this->assertDatabaseHas('songs', ['title' => 'A Brand New Song']);
        $newSong = Song::where('title', 'A Brand New Song')->first();
        $this->assertNotNull($newSong);

        $this->assertDatabaseHas('scripture_references', ['song_id' => $newSong->id, 'reference_string' => 'John 3:16']);
        $this->assertDatabaseHas('scripture_references', ['song_id' => $newSong->id, 'reference_string' => 'Psalm 23:1-3']);

        $response->assertRedirect('/church/members/songs');
        $response->assertSessionHas('success');
    }

    #[Test]
    public function store_song_fails_with_invalid_data()
    {
        $response = $this->actingAs($this->adminUser)->post('/church/members/songs', [
            'title' => '', // Title is required
            'lyrics' => 'Some lyrics',
        ]);
        $response->assertSessionHasErrors('title');
        $this->assertDatabaseMissing('songs', ['lyrics' => 'Some lyrics']);
    }

    // 5. testSongShowPageLoads
    #[Test]
    public function song_show_page_loads_for_everyone()
    {
        $song = Song::factory()->create();
        ScriptureReference::factory()->forSong($song)->create(['reference_string' => 'Gen 1:1']);

        $response = $this->get("/church/members/songs/{$song->id}");
        $response->assertOk();
        $response->assertViewIs('songs.show');
        $response->assertSee($song->title);
        $response->assertSee($song->lyrics);
        $response->assertSee('Gen 1:1');
    }

    #[Test]
    public function song_show_page_returns_404_for_non_existent_song()
    {
        $this->get('/songs/9999')->assertNotFound();
    }

    // 6. testSongEditPageLoads
    #[Test]
    public function song_edit_page_loads_for_admin_users()
    {
        $song = Song::factory()->create();
        $response = $this->actingAs($this->adminUser)->get("/church/members/songs/{$song->id}/edit");
        $response->assertOk();
        $response->assertViewIs('songs.edit');
        $response->assertSee($song->title);
    }

    #[Test]
    public function song_edit_page_returns_404_for_non_existent_song_for_admin()
    {
        $this->actingAs($this->adminUser)->get('/songs/9999/edit')->assertNotFound();
    }

    // 7. testUpdateExistingSong
    #[Test]
    public function admin_user_can_update_existing_song()
    {
        $song = Song::factory()->create();
        $updateData = [
            'title' => 'Updated Song Title',
            'lyrics' => 'Updated lyrics here.',
            'copyright' => $song->copyright,
            'scripture_references' => ['Romans 8:28', '1 Corinthians 13'],
        ];

        $response = $this->actingAs($this->adminUser)->put("/church/members/songs/{$song->id}", $updateData);

        $this->assertDatabaseHas('songs', ['id' => $song->id, 'title' => 'Updated Song Title']);
        $updatedSong = Song::find($song->id);
        $this->assertEquals('Updated lyrics here.', $updatedSong->lyrics);

        $this->assertDatabaseHas('scripture_references', ['song_id' => $song->id, 'reference_string' => 'Romans 8:28']);
        $this->assertDatabaseHas('scripture_references', ['song_id' => $song->id, 'reference_string' => '1 Corinthians 13']);

        $response->assertRedirect('/church/members/songs');
        $response->assertSessionHas('success');
    }

    #[Test]
    public function update_song_fails_with_invalid_data()
    {
        $song = Song::factory()->create();
        $response = $this->actingAs($this->adminUser)->put("/church/members/songs/{$song->id}", [
            'title' => '', // Title is required
        ]);
        $response->assertSessionHasErrors('title');
        $this->assertNotEquals('', Song::find($song->id)->title);
    }

    // 8. testDestroySong
    #[Test]
    public function admin_user_can_destroy_song()
    {
        $song = Song::factory()->create();
        ScriptureReference::factory()->count(2)->forSong($song)->create();

        $response = $this->actingAs($this->adminUser)->delete("/church/members/songs/{$song->id}");

        $this->assertDatabaseMissing('songs', ['id' => $song->id]);
        $this->assertDatabaseMissing('scripture_references', ['song_id' => $song->id]);

        $response->assertRedirect('/church/members/songs');
        $response->assertSessionHas('success');
    }

    #[Test]
    public function destroy_non_existent_song_returns_404_for_admin()
    {
        $this->actingAs($this->adminUser)->delete('/songs/9999')->assertNotFound();
    }

    // 9. testSongSearch (Placeholder - depends on actual search implementation)
    #[Test]
    public function song_search_returns_relevant_results()
    {
        $song1 = Song::factory()->create(['title' => 'Amazing Grace']);
        $song2 = Song::factory()->create(['title' => 'Grace Alone']);
        $song3 = Song::factory()->create(['title' => 'Mighty Fortress']);

        $response = $this->actingAs($this->adminUser)->get('/church/members/songs/search?q=Grace');
        $response->assertOk();
        $response->assertViewIs('songs.index');
        $response->assertSee($song1->title);
        $response->assertSee($song2->title);
        $response->assertDontSee($song3->title);
    }

    // 10. testScriptureReferenceLinking is partially covered in store/update tests.
    // Additional specific tests can be added if there's a dedicated UI for managing these links
    // or more complex logic.

    #[Test]
    public function filtering_songs_by_scripture_reference()
    {
        $song1 = Song::factory()->create(['title' => 'Song with John 3:16']);
        $song2 = Song::factory()->create(['title' => 'Song with Psalm 23']);
        
        ScriptureReference::factory()->forSong($song1)->create(['reference_string' => 'John 3:16']);
        ScriptureReference::factory()->forSong($song2)->create(['reference_string' => 'Psalm 23']);

        $response = $this->get('/church/members/songs/scripture/john-3-16');
        $response->assertOk();
        $response->assertViewIs('songs.index');
        $response->assertSee($song1->title);
        $response->assertDontSee($song2->title);
    }
}
