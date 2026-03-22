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
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicSongDetailTest extends TestCase
{
    use DatabaseTransactions;

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
            'service' => SermonService::EVENING,
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
            'service' => SermonService::MORNING,
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
            'status' => ProcessingStatus::COMPLETED,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $detectedItem->id,
            'section_type' => ServiceSectionType::SONG,
            'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
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
}
