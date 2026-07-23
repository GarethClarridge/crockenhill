<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RealignSectionItemsCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function dry_run_writes_nothing_and_apply_realigns_by_normalized_title(): void
    {
        [$section, $correctItem, $wrongItem] = $this->misalignedSection();

        $this->artisan('service:realign-section-items')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();
        $this->assertSame($wrongItem->id, $section->refresh()->church_service_item_id);

        $this->artisan('service:realign-section-items --apply')
            ->expectsOutputToContain('APPLYING')
            ->assertSuccessful();
        $this->assertSame($correctItem->id, $section->refresh()->church_service_item_id);
    }

    #[Test]
    public function service_scope_and_superseded_flag_are_respected(): void
    {
        [$target, , $targetWrong] = $this->misalignedSection();
        [$other, , $otherWrong] = $this->misalignedSection();
        [$superseded, , $supersededWrong] = $this->misalignedSection(superseded: true);

        $this->artisan('service:realign-section-items --apply --service='.$target->processingLog->church_service_id)
            ->assertSuccessful();

        $this->assertNotSame($targetWrong->id, $target->refresh()->church_service_item_id);
        $this->assertSame($otherWrong->id, $other->refresh()->church_service_item_id);
        $this->assertSame($supersededWrong->id, $superseded->refresh()->church_service_item_id);

        $this->artisan('service:realign-section-items --apply --include-superseded')
            ->assertSuccessful();
        $this->assertNotSame($supersededWrong->id, $superseded->refresh()->church_service_item_id);
    }

    /**
     * @return array{ServiceSection, ChurchServiceItem, ChurchServiceItem}
     */
    private function misalignedSection(bool $superseded = false): array
    {
        $service = ChurchService::factory()->create();
        $plannedSong = Song::factory()->create(['title' => 'Great Is The Lord #179']);
        $duplicateSong = Song::factory()->create(['title' => 'Great Is The Lord']);
        $wrongSong = Song::factory()->create(['title' => 'God is for us']);
        $correctItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Great Is The Lord #179',
            'song_id' => $plannedSong->id,
        ]);
        $wrongItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 2,
            'type' => 'songs',
            'title' => 'God is for us',
            'song_id' => $wrongSong->id,
        ]);
        $run = MediaProcessingLog::factory()->livestream()->failed()->create([
            'church_service_id' => $service->id,
            'superseded_at' => $superseded ? now() : null,
        ]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $wrongItem->id,
            'section_type' => ServiceSectionType::Song,
            'title' => 'Great Is The Lord',
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => $duplicateSong->id],
        ]);

        return [$section, $correctItem, $wrongItem];
    }
}
