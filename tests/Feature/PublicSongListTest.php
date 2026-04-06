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
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicSongListTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get(route('church.songs.index'))
            ->assertRedirect('/login');
    }

    #[Test]
    public function feature_returns_not_found_when_service_tracking_is_disabled(): void
    {
        config()->set('service-tracking.enabled', false);

        $this->actingAs(User::factory()->create());

        $this->get(route('church.songs.index'))
            ->assertNotFound();
    }

    #[Test]
    public function livestreamed_services_only_count_detected_and_matched_songs(): void
    {
        $this->actingAs(User::factory()->create());

        $countedSong = Song::factory()->create([
            'title' => 'Counted Song',
        ]);
        $oosOnlySong = Song::factory()->create([
            'title' => 'Order Only Song',
        ]);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-02-08',
            'service' => SermonService::MORNING,
        ]);

        $countedItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'title' => $countedSong->title,
            'song_id' => $countedSong->id,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'title' => $oosOnlySong->title,
            'song_id' => $oosOnlySong->id,
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'status' => ProcessingStatus::COMPLETED,
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $countedItem->id,
            'section_type' => ServiceSectionType::SONG,
            'song_match_type' => ServiceSectionSongMatchType::CONFIRMED->value,
            'metadata' => ['oos_alignment' => []],
        ]);

        $response = $this->get(route('church.songs.index'));

        $response->assertOk();
        $response->assertSee('Counted Song');
        $response->assertDontSee('Order Only Song');
        $response->assertViewHas('songs', function (LengthAwarePaginator $songs) use ($countedSong): bool {
            $song = $songs->getCollection()->firstWhere('id', $countedSong->id);

            return $song !== null
                && (int) $song->usage_count === 1
                && (string) $song->last_sung_date === '2026-02-08';
        });
    }

    #[Test]
    public function non_livestreamed_services_count_order_of_service_songs(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create([
            'title' => 'Evening Hymn',
        ]);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-02-15',
            'service' => SermonService::EVENING,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'title' => $song->title,
            'song_id' => $song->id,
        ]);

        $response = $this->get(route('church.songs.index'));

        $response->assertOk();
        $response->assertSee('Evening Hymn');
        $response->assertViewHas('songs', function (LengthAwarePaginator $songs) use ($song): bool {
            $listedSong = $songs->getCollection()->firstWhere('id', $song->id);

            return $listedSong !== null
                && (int) $listedSong->usage_count === 1
                && (string) $listedSong->last_sung_date === '2026-02-15';
        });
    }

    #[Test]
    public function services_with_processing_logs_but_no_completed_livestream_match_remain_excluded_under_current_policy(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create([
            'title' => 'Failed Processing Song',
        ]);

        $churchService = ChurchService::factory()->create([
            'date' => '2026-02-22',
            'service' => SermonService::MORNING,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $churchService->id,
            'type' => 'songs',
            'title' => $song->title,
            'song_id' => $song->id,
        ]);

        MediaProcessingLog::factory()->livestream()->create([
            'church_service_id' => $churchService->id,
            'status' => ProcessingStatus::FAILED,
        ]);

        $this->get(route('church.songs.index'))
            ->assertOk()
            ->assertDontSee('Failed Processing Song');
    }

    #[Test]
    public function date_filter_limits_results_to_the_current_year(): void
    {
        $this->actingAs(User::factory()->create());

        $song = Song::factory()->create([
            'title' => 'Year Filter Song',
        ]);

        foreach (['2026-02-23', '2025-11-02'] as $date) {
            $service = ChurchService::factory()->create([
                'date' => $date,
                'service' => SermonService::EVENING,
            ]);

            ChurchServiceItem::factory()->create([
                'church_service_id' => $service->id,
                'type' => 'songs',
                'title' => $song->title,
                'song_id' => $song->id,
            ]);
        }

        $response = $this->get(route('church.songs.index', ['range' => 'year']));

        $response->assertOk();
        $response->assertViewHas('selectedRange', 'year');
        $response->assertViewHas('songs', function (LengthAwarePaginator $songs) use ($song): bool {
            $listedSong = $songs->getCollection()->firstWhere('id', $song->id);

            return $listedSong !== null
                && (int) $listedSong->usage_count === 1
                && (string) $listedSong->last_sung_date === '2026-02-23';
        });
    }

    #[Test]
    public function songs_are_ranked_by_qualifying_usage_count(): void
    {
        $this->actingAs(User::factory()->create());

        $mostUsedSong = Song::factory()->create([
            'title' => 'Most Used Song',
        ]);
        $lessUsedSong = Song::factory()->create([
            'title' => 'Less Used Song',
        ]);

        foreach (['2026-01-05', '2026-01-12'] as $date) {
            $service = ChurchService::factory()->create([
                'date' => $date,
                'service' => SermonService::EVENING,
            ]);

            ChurchServiceItem::factory()->create([
                'church_service_id' => $service->id,
                'type' => 'songs',
                'title' => $mostUsedSong->title,
                'song_id' => $mostUsedSong->id,
            ]);
        }

        $service = ChurchService::factory()->create([
            'date' => '2026-01-19',
            'service' => SermonService::EVENING,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'type' => 'songs',
            'title' => $lessUsedSong->title,
            'song_id' => $lessUsedSong->id,
        ]);

        $response = $this->get(route('church.songs.index'));

        $response->assertOk();
        $response->assertViewHas('songs', function (LengthAwarePaginator $songs) use ($mostUsedSong, $lessUsedSong): bool {
            return $songs->getCollection()->pluck('id')->take(2)->values()->all() === [
                $mostUsedSong->id,
                $lessUsedSong->id,
            ];
        });
    }

    #[Test]
    public function header_navigation_shows_songs_link_to_authenticated_users(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/church')
            ->assertOk()
            ->assertSee('href="'.route('church.songs.index').'"', false);
    }

    #[Test]
    public function header_navigation_hides_songs_link_from_guests(): void
    {
        $this->get('/church')
            ->assertOk()
            ->assertDontSee('href="'.route('church.songs.index').'"', false);
    }
}
