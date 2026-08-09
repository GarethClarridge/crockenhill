<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongUsageReport;
use App\Models\SongVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicSongDetailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $song = Song::factory()->create();

        $this->get(route('church.songs.show', $song->slug))
            ->assertRedirect('/login');
    }

    #[Test]
    public function feature_returns_not_found_when_service_tracking_is_disabled(): void
    {
        config()->set('service-tracking.enabled', false);

        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create();

        $this->get(route('church.songs.show', $song->slug))
            ->assertNotFound();
    }

    #[Test]
    public function detail_page_renders_for_song_slug(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create([
            'title' => 'Be Thou My Vision',
            'slug' => 'be-thou-my-vision',
        ]);

        $response = $this->get(route('church.songs.show', $song->slug));

        $response->assertOk();
        $response->assertSee('Be Thou My Vision');
        $response->assertSee(route('church.songs.index'), false);
    }

    #[Test]
    public function lyrics_are_displayed_when_available(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create([
            'title' => 'Great Is Thy Faithfulness',
            'lyrics_plain' => "Morning by morning\nNew mercies I see",
        ]);

        $this->get(route('church.songs.show', $song->slug))
            ->assertOk()
            ->assertSee('Morning by morning')
            ->assertSee('New mercies I see');
    }

    #[Test]
    public function usage_history_only_includes_qualifying_usage(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create([
            'title' => 'Amazing Grace',
            'slug' => 'amazing-grace',
        ]);

        $nonLivestreamedService = ChurchService::factory()->create([
            'date' => '2026-02-16',
            'service' => SermonService::Evening,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $nonLivestreamedService->id,
            'type' => 'songs',
            'title' => 'Amazing Grace',
            'song_id' => $song->id,
            'position' => 2,
        ]);

        $livestreamedService = ChurchService::factory()->create([
            'date' => '2026-02-09',
            'service' => SermonService::Morning,
        ]);

        $detectedItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $livestreamedService->id,
            'type' => 'songs',
            'title' => 'Amazing Grace',
            'song_id' => $song->id,
            'position' => 3,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $livestreamedService->id,
            'type' => 'songs',
            'title' => 'Amazing Grace (OoS Only)',
            'song_id' => $song->id,
            'position' => 4,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $livestreamedService->id,
            'status' => ProcessingStatus::Completed,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $detectedItem->id,
            'section_type' => ServiceSectionType::Song,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'metadata' => ['oos_alignment' => []],
        ]);

        $response = $this->get(route('church.songs.show', $song->slug));

        $response->assertOk();
        $response->assertSee('16 Feb 2026');
        $response->assertSee('9 Feb 2026');
        $response->assertSee('Evening');
        $response->assertSee('Morning');
        $response->assertSee('Amazing Grace');
        $response->assertDontSee('Amazing Grace (OoS Only)');
        $response->assertSee('Used in 2 services');
        $response->assertSee('Last sung 16 February 2026');
        $response->assertSee('Title in Service');
    }

    #[Test]
    public function date_only_usage_is_labelled_without_linking_to_an_invented_service(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create(['slug' => 'historic-hymn']);
        SongUsageReport::factory()->create([
            'song_id' => $song->id,
            'used_on' => '2007-06-17',
            'reported_service' => null,
            'reported_title' => 'Historic hymn title',
        ]);

        $this->get(route('church.songs.show', $song->slug))
            ->assertOk()
            ->assertSee('17 Jun 2007')
            ->assertSee('Service not recorded')
            ->assertSee('Historic hymn title')
            ->assertSee('Used in 1 service')
            ->assertDontSee('/church/services/2007-06-17', false);
    }

    #[Test]
    public function ccli_number_is_displayed_when_available(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create([
            'title' => 'Cornerstone',
            'slug' => 'cornerstone',
            'ccli_number' => '123456',
        ]);

        $this->get(route('church.songs.show', $song->slug))
            ->assertOk()
            ->assertSee('CCLI 123456');
    }

    #[Test]
    public function video_player_is_shown_when_song_has_a_video(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create(['title' => 'In Christ Alone']);

        SongVideo::factory()->create([
            'song_id' => $song->id,
            'video_file_path' => 'sermons/songs/'.$song->id.'/test-video.mp4',
            'recorded_date' => '2026-03-01',
        ]);

        $response = $this->get(route('church.songs.show', $song->slug));

        $response->assertOk();
        $response->assertSee('<video', false);
        $response->assertSee('sermons/songs/'.$song->id.'/test-video.mp4', false);
    }

    #[Test]
    public function video_player_is_not_shown_when_song_has_no_video(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create(['title' => 'How Great Thou Art']);

        $response = $this->get(route('church.songs.show', $song->slug));

        $response->assertOk();
        $response->assertDontSee('<video', false);
    }

    #[Test]
    public function featured_video_takes_priority_over_most_recent(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create(['title' => 'Blessed Assurance']);

        SongVideo::factory()->create([
            'song_id' => $song->id,
            'video_file_path' => 'sermons/songs/'.$song->id.'/recent.mp4',
            'recorded_date' => '2026-03-20',
            'is_featured' => false,
        ]);

        SongVideo::factory()->featured()->create([
            'song_id' => $song->id,
            'video_file_path' => 'sermons/songs/'.$song->id.'/featured.mp4',
            'recorded_date' => '2026-01-01',
        ]);

        $response = $this->get(route('church.songs.show', $song->slug));

        $response->assertOk();
        $response->assertSee('sermons/songs/'.$song->id.'/featured.mp4', false);
        $response->assertDontSee('recent.mp4', false);
    }

    #[Test]
    public function manual_upload_without_featured_flag_does_not_show_video(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create(['title' => 'To God Be The Glory']);

        SongVideo::factory()->manual()->create([
            'song_id' => $song->id,
            'video_file_path' => 'sermons/songs/'.$song->id.'/manual.mp4',
        ]);

        $response = $this->get(route('church.songs.show', $song->slug));

        $response->assertOk();
        $response->assertDontSee('<video', false);
    }

    #[Test]
    public function unauthenticated_users_are_still_redirected_to_login(): void
    {
        $song = Song::factory()->create();

        SongVideo::factory()->create([
            'song_id' => $song->id,
            'recorded_date' => '2026-03-01',
        ]);

        $this->get(route('church.songs.show', $song->slug))
            ->assertRedirect('/login');
    }
}
