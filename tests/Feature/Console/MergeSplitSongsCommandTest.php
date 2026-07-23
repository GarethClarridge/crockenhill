<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MergeSplitSongsCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function dry_run_writes_nothing_and_apply_merges_the_group(): void
    {
        [$anchor, $fragment] = $this->splitSong();

        $this->artisan('service:merge-split-songs')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();
        $this->assertModelExists($fragment);
        $this->assertSame(80.0, $anchor->refresh()->end_time);

        $this->artisan('service:merge-split-songs --apply')
            ->expectsOutputToContain('APPLYING')
            ->assertSuccessful();
        $this->assertModelMissing($fragment);
        $this->assertSame(120.0, $anchor->refresh()->end_time);
    }

    #[Test]
    public function service_scope_and_superseded_flag_are_respected(): void
    {
        [$target, $targetFragment] = $this->splitSong();
        [, $otherFragment] = $this->splitSong();
        [, $supersededFragment] = $this->splitSong(superseded: true);

        $this->artisan('service:merge-split-songs --apply --service='.$target->processingLog->church_service_id)
            ->assertSuccessful();

        $this->assertModelMissing($targetFragment);
        $this->assertModelExists($otherFragment);
        $this->assertModelExists($supersededFragment);

        $this->artisan('service:merge-split-songs --apply --include-superseded')
            ->assertSuccessful();
        $this->assertModelMissing($supersededFragment);
    }

    /**
     * @return array{ServiceSection, ServiceSection}
     */
    private function splitSong(bool $superseded = false): array
    {
        $service = ChurchService::factory()->create();
        $run = MediaProcessingLog::factory()->livestream()->failed()->create([
            'church_service_id' => $service->id,
            'superseded_at' => $superseded ? now() : null,
        ]);
        $anchor = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'start_time' => 0,
            'end_time' => 80,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed,
            'metadata' => ['song_id' => 10],
        ]);
        $fragment = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 2,
            'start_time' => 80,
            'end_time' => 120,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched,
            'metadata' => ['confidence_level' => 'low'],
        ]);

        return [$anchor, $fragment];
    }
}
