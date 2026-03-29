<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SermonService;
use App\Livewire\Admin\ChurchServices\ListSongs;
use App\Livewire\Admin\ChurchServices\ShowSong;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Models\SongAuthor;
use App\Models\SongBook;
use App\Models\SongVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminSongCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function admin_can_filter_song_list_and_usage_by_service_slot(): void
    {
        $this->actingAs($this->admin);

        $morningService = ChurchService::factory()->create([
            'date' => '2026-02-01',
            'service' => SermonService::MORNING,
        ]);
        $eveningService = ChurchService::factory()->create([
            'date' => '2026-02-08',
            'service' => SermonService::EVENING,
        ]);

        $songA = Song::factory()->create([
            'title' => 'Song A',
            'canonical_key' => 'song a@',
        ]);
        $songB = Song::factory()->create([
            'title' => 'Song B',
            'canonical_key' => 'song b@',
        ]);

        $authorOne = SongAuthor::factory()->create(['display_name' => 'Writer One']);
        $authorTwo = SongAuthor::factory()->create(['display_name' => 'Writer Two']);
        $songA->authors()->attach($authorOne->id, ['author_type' => 'words']);
        $songB->authors()->attach($authorTwo->id, ['author_type' => 'words']);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $morningService->id,
            'type' => 'songs',
            'title' => 'Song A',
            'song_id' => $songA->id,
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $eveningService->id,
            'type' => 'songs',
            'title' => 'Song A',
            'song_id' => $songA->id,
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $eveningService->id,
            'type' => 'songs',
            'title' => 'Song B',
            'song_id' => $songB->id,
        ]);

        Livewire::test(ListSongs::class)
            ->assertSee('Song A')
            ->assertSee('Song B')
            ->set('search', 'Writer Two')
            ->assertSee('Song B')
            ->assertDontSee('Song A')
            ->set('search', 'song b@')
            ->assertSee('Song B')
            ->assertDontSee('Song A')
            ->set('search', '')
            ->set('serviceFilter', SermonService::MORNING->value)
            ->assertViewHas('songs', function ($songs) use ($songA, $songB): bool {
                $collection = $songs->getCollection()->keyBy('id');

                return (int) $collection[$songA->id]->usage_count === 1
                    && (int) $collection[$songB->id]->usage_count === 0;
            });
    }

    #[Test]
    public function admin_can_sort_song_list_by_clicking_table_header_actions(): void
    {
        $this->actingAs($this->admin);

        Song::factory()->create([
            'title' => 'Zulu Song',
            'canonical_key' => 'zulu song@',
        ]);
        Song::factory()->create([
            'title' => 'Alpha Song',
            'canonical_key' => 'alpha song@',
        ]);

        Livewire::test(ListSongs::class)
            ->call('sort', 'title')
            ->assertSet('sortBy', 'title')
            ->assertSet('sortDirection', 'asc')
            ->assertViewHas('songs', function ($songs): bool {
                return $songs->getCollection()->pluck('title')->values()->all() === ['Alpha Song', 'Zulu Song'];
            })
            ->call('sort', 'title')
            ->assertSet('sortBy', 'title')
            ->assertSet('sortDirection', 'desc')
            ->assertViewHas('songs', function ($songs): bool {
                return $songs->getCollection()->pluck('title')->values()->all() === ['Zulu Song', 'Alpha Song'];
            });
    }

    #[Test]
    public function admin_can_view_song_detail_with_lyrics_and_usage_history(): void
    {
        $this->actingAs($this->admin);

        $service = ChurchService::factory()->create([
            'date' => '2026-03-10',
            'service' => SermonService::MORNING,
        ]);

        $song = Song::factory()->create([
            'title' => 'Detail Song',
            'canonical_key' => 'detail song@',
            'lyrics_plain' => "Verse line one\n\nVerse line two",
            'import_metadata' => [
                'lyrics_parse_warnings' => ['Lyrics XML could not be parsed.'],
                'source_song_ids' => [100],
            ],
        ]);

        $author = SongAuthor::factory()->create([
            'display_name' => 'Writer Three',
        ]);
        $book = SongBook::factory()->create([
            'name' => 'Blue Book',
            'source_book_id' => 40,
        ]);

        $song->authors()->attach($author->id, ['author_type' => 'words']);
        $song->books()->attach($book->id, ['entry' => '90']);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'type' => 'songs',
            'title' => 'Detail Song',
            'song_id' => $song->id,
        ]);

        Livewire::test(ShowSong::class, ['song' => $song])
            ->assertSee('Detail Song')
            ->assertSee('Verse line one')
            ->assertSee('Lyrics XML could not be parsed.')
            ->assertSee('Writer Three')
            ->assertSee('Blue Book')
            ->assertSee('Recent usage')
            ->assertSee('10 Mar 2026');
    }

    #[Test]
    public function admin_can_see_song_videos_on_detail_page(): void
    {
        $this->actingAs($this->admin);

        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create(['title' => 'Amazing Grace']);

        SongVideo::factory()->create([
            'song_id' => $song->id,
            'recorded_date' => '2026-01-12',
            'duration' => 185.0,
            'is_featured' => true,
        ]);
        SongVideo::factory()->create([
            'song_id' => $song->id,
            'recorded_date' => '2025-11-03',
            'duration' => 210.5,
            'is_featured' => false,
        ]);

        Livewire::test(ShowSong::class, ['song' => $song])
            ->assertSee('Song videos')
            ->assertSee('12 Jan 2026')
            ->assertSee('3 Nov 2025')
            ->assertSee('Featured')
            ->assertSee('Unfeature')
            ->assertSee('Feature');
    }

    #[Test]
    public function admin_sees_empty_state_when_song_has_no_videos(): void
    {
        $this->actingAs($this->admin);

        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();

        Livewire::test(ShowSong::class, ['song' => $song])
            ->assertSee('Song videos')
            ->assertSee('No video recordings saved for this song yet.');
    }

    #[Test]
    public function admin_can_feature_a_song_video(): void
    {
        $this->actingAs($this->admin);

        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $video = SongVideo::factory()->create([
            'song_id' => $song->id,
            'is_featured' => false,
        ]);

        Livewire::test(ShowSong::class, ['song' => $song])
            ->call('featureVideo', $video->id);

        $this->assertTrue($video->fresh()->is_featured);
    }

    #[Test]
    public function admin_can_unfeature_a_song_video(): void
    {
        $this->actingAs($this->admin);

        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $video = SongVideo::factory()->featured()->create([
            'song_id' => $song->id,
        ]);

        Livewire::test(ShowSong::class, ['song' => $song])
            ->call('unfeatureVideo', $video->id);

        $this->assertFalse($video->fresh()->is_featured);
    }

    #[Test]
    public function admin_can_delete_a_song_video(): void
    {
        $this->actingAs($this->admin);

        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $song = Song::factory()->create();
        $video = SongVideo::factory()->create([
            'song_id' => $song->id,
            'video_file_path' => 'sermons/songs/'.$song->id.'/test.mp4',
        ]);

        Livewire::test(ShowSong::class, ['song' => $song])
            ->call('deleteVideo', $video->id);

        $this->assertDatabaseMissing('song_videos', ['id' => $video->id]);
    }

    #[Test]
    public function non_admin_users_are_forbidden_from_song_components(): void
    {
        $song = Song::factory()->create();
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $this->get(route('admin.services.songs.index'))->assertForbidden();
        $this->get(route('admin.services.songs.show', $song))->assertForbidden();
    }
}
