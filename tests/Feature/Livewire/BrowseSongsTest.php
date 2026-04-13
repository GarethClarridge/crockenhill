<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SermonService;
use App\Livewire\Church\Songs\BrowseSongs;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Models\User;
use App\Services\PublicSongCatalogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrowseSongsTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    // ── route renders component ───────────────────────────────────────────

    #[Test]
    public function songs_index_renders_the_livewire_component(): void
    {
        $this->actingAs($this->user)
            ->get(route('church.songs.index'))
            ->assertOk()
            ->assertSeeLivewire(BrowseSongs::class);
    }

    #[Test]
    public function component_renders_successfully(): void
    {
        $this->actingAs($this->user);

        Livewire::test(BrowseSongs::class)
            ->assertStatus(200);
    }

    // ── default state ─────────────────────────────────────────────────────

    #[Test]
    public function all_range_shows_full_catalogue_including_zero_usage_songs(): void
    {
        $this->actingAs($this->user);

        $unsung = Song::factory()->create(['title' => 'Never Sung Hymn']);

        Livewire::test(BrowseSongs::class)
            ->set('range', PublicSongCatalogService::RANGE_ALL)
            ->assertSee('Never Sung Hymn');
    }

    #[Test]
    public function default_range_is_recent(): void
    {
        $this->actingAs($this->user);

        Livewire::test(BrowseSongs::class)
            ->assertSet('range', PublicSongCatalogService::RANGE_RECENT);
    }

    // ── range filter ──────────────────────────────────────────────────────

    #[Test]
    public function recent_range_hides_songs_not_sung_in_last_3_years(): void
    {
        $this->actingAs($this->user);

        $oldSong = Song::factory()->create(['title' => 'Not Sung Recently']);
        $churchService = ChurchService::factory()->create([
            'date' => '2022-01-15',
            'service' => SermonService::Morning,
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $oldSong->id,
        ]);

        Livewire::test(BrowseSongs::class)
            ->set('range', PublicSongCatalogService::RANGE_RECENT)
            ->assertDontSee('Not Sung Recently');
    }

    #[Test]
    public function recent_range_shows_songs_sung_within_last_3_years(): void
    {
        $this->actingAs($this->user);

        $song = Song::factory()->create(['title' => 'Sung Recently']);
        $churchService = ChurchService::factory()->create([
            'date' => now()->format('Y-01-15'),
            'service' => SermonService::Morning,
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'song_id' => $song->id,
        ]);

        Livewire::test(BrowseSongs::class)
            ->set('range', PublicSongCatalogService::RANGE_RECENT)
            ->assertSee('Sung Recently');
    }

    #[Test]
    public function changing_range_resets_pagination(): void
    {
        $this->actingAs($this->user);

        Livewire::test(BrowseSongs::class)
            ->set('paginators.page', 2)
            ->set('range', PublicSongCatalogService::RANGE_RECENT)
            ->assertSet('paginators.page', 1);
    }

    // ── search ────────────────────────────────────────────────────────────

    #[Test]
    public function search_state_is_settable(): void
    {
        $this->actingAs($this->user);

        Livewire::test(BrowseSongs::class)
            ->set('search', 'Amazing Grace')
            ->assertSet('search', 'Amazing Grace');
    }

    #[Test]
    public function changing_search_resets_pagination(): void
    {
        $this->actingAs($this->user);

        Livewire::test(BrowseSongs::class)
            ->set('paginators.page', 2)
            ->set('search', 'grace')
            ->assertSet('paginators.page', 1);
    }

    // ── URL state ─────────────────────────────────────────────────────────

    #[Test]
    public function search_maps_to_q_in_url(): void
    {
        $this->actingAs($this->user);

        Livewire::withQueryParams(['q' => 'grace'])
            ->test(BrowseSongs::class)
            ->assertSet('search', 'grace');
    }

    #[Test]
    public function range_is_reflected_in_url_params(): void
    {
        $this->actingAs($this->user);

        Livewire::withQueryParams(['range' => 'recent'])
            ->test(BrowseSongs::class)
            ->assertSet('range', 'recent');
    }

    // ── empty states ──────────────────────────────────────────────────────

    #[Test]
    public function active_search_with_no_results_shows_search_empty_state(): void
    {
        $this->actingAs($this->user);

        Livewire::test(BrowseSongs::class)
            ->set('search', 'xyzzy_no_match_expected')
            ->assertSee('No songs match your search');
    }

    #[Test]
    public function recent_range_with_no_songs_shows_recent_empty_state(): void
    {
        $this->actingAs($this->user);

        // Delete all songs to guarantee empty recent results
        Song::query()->delete();

        Livewire::test(BrowseSongs::class)
            ->set('range', PublicSongCatalogService::RANGE_RECENT)
            ->assertSee('No songs sung in the last 3 years');
    }

    // ── zero-usage copy ───────────────────────────────────────────────────

    #[Test]
    public function songs_with_no_usage_display_not_yet_sung(): void
    {
        $this->actingAs($this->user);

        Song::factory()->create(['title' => 'Unsung Song']);

        Livewire::test(BrowseSongs::class)
            ->set('range', PublicSongCatalogService::RANGE_ALL)
            ->assertSee('Not yet sung');
    }

    // ── search results ────────────────────────────────────────────────────

    #[Test]
    public function search_filters_results_to_matching_songs(): void
    {
        $this->actingAs($this->user);

        Song::factory()->create(['title' => 'Amazing Grace']);
        Song::factory()->create(['title' => 'How Great Thou Art']);

        Livewire::test(BrowseSongs::class)
            ->set('range', PublicSongCatalogService::RANGE_ALL)
            ->set('search', 'Amazing')
            ->assertSee('Amazing Grace')
            ->assertDontSee('How Great Thou Art');
    }
}
