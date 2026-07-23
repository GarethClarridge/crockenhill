<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\SongContinuationMerger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongContinuationMergerTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_groups_an_unmatched_song_and_explicit_lyric_tail_after_a_confirmed_song(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $anchor = $this->section($run, 1, ServiceSectionType::Song, 0.0, 80.0, [
            'title' => 'Lord Jesus build your church today',
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => 594],
        ]);
        $unmatched = $this->section($run, 2, ServiceSectionType::Song, 80.0, 190.0, [
            'song_match_type' => ServiceSectionSongMatchType::Unmatched,
            'metadata' => ['confidence_level' => 'low'],
        ]);
        $tail = $this->section($run, 3, ServiceSectionType::Other, 190.0, 240.0, [
            'confidence' => 0.4,
            'metadata' => [
                'confidence_level' => 'low',
                'ai_notes' => ['Likely the tail end of a song spilling into this segment.'],
            ],
        ]);
        $sermon = $this->section($run, 4, ServiceSectionType::Sermon, 240.0, 1200.0);

        $groups = app(SongContinuationMerger::class)->preview($run);

        $this->assertCount(1, $groups);
        $this->assertSame($anchor->id, $groups[0]['anchor']->id);
        $this->assertSame([$unmatched->id, $tail->id], $groups[0]['absorbed']->pluck('id')->all());
        $this->assertNotContains($sermon->id, $groups[0]['absorbed']->pluck('id')->all());
    }

    #[Test]
    public function a_different_confirmed_song_or_plan_linked_section_is_a_hard_stop(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $first = $this->section($run, 1, ServiceSectionType::Song, 0.0, 80.0, [
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => 10],
        ]);
        $this->section($run, 2, ServiceSectionType::Song, 80.0, 160.0, [
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => 11],
        ]);
        $this->section($run, 3, ServiceSectionType::Song, 160.0, 200.0, [
            'song_match_type' => ServiceSectionSongMatchType::Unmatched,
        ]);

        $groups = app(SongContinuationMerger::class)->preview($run);

        $this->assertFalse(collect($groups)->contains(
            fn (array $group): bool => $group['anchor']->id === $first->id
        ));
    }

    #[Test]
    public function repair_mode_can_bridge_a_short_removed_prompt_echo_gap(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $anchor = $this->section($run, 1, ServiceSectionType::Song, 0.0, 100.0, [
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => 10],
        ]);
        $fragment = $this->section($run, 3, ServiceSectionType::Song, 103.5, 120.0, [
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => 10],
        ]);

        $this->assertSame([], app(SongContinuationMerger::class)->preview($run, conservative: true));

        $groups = app(SongContinuationMerger::class)->preview($run, conservative: false);
        $this->assertSame($anchor->id, $groups[0]['anchor']->id);
        $this->assertSame([$fragment->id], $groups[0]['absorbed']->pluck('id')->all());
    }

    #[Test]
    public function repair_mode_can_merge_same_song_fragments_with_shifted_plan_links(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->create();
        $anchor = $this->section($run, 1, ServiceSectionType::Song, 0.0, 100.0, [
            'church_service_item_id' => ChurchServiceItem::factory(),
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => 10],
        ]);
        $fragment = $this->section($run, 2, ServiceSectionType::Song, 100.0, 120.0, [
            'church_service_item_id' => ChurchServiceItem::factory(),
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => 10],
        ]);

        $this->assertSame([], app(SongContinuationMerger::class)->preview($run, conservative: true));

        $groups = app(SongContinuationMerger::class)->preview($run, conservative: false);
        $this->assertSame($anchor->id, $groups[0]['anchor']->id);
        $this->assertSame([$fragment->id], $groups[0]['absorbed']->pluck('id')->all());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function section(
        MediaProcessingLog $run,
        int $order,
        ServiceSectionType $type,
        float $start,
        float $end,
        array $overrides = [],
    ): ServiceSection {
        return ServiceSection::factory()->create(array_merge([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => $type,
            'section_order' => $order,
            'start_time' => $start,
            'end_time' => $end,
            'source_segment_ids' => [$order],
        ], $overrides));
    }
}
