<?php

declare(strict_types=1);

namespace Tests\Integration\Services\HistoricMedia;

use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\ServiceSectionType;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Models\SongVideo;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricProcessingResultInventoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_a_stable_graph_using_portable_relationship_keys(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => 'historic-run',
            'processing_metadata' => [
                'service_artifacts' => [
                    ['kind' => 'rms', 'path' => 'historic/run/rms.json', 'sha256' => str_repeat('a', 64)],
                ],
                'owner_user_id' => 999,
                'queue_name' => 'media',
            ],
        ]);
        SermonProcessingStep::factory()->create([
            'processing_id' => $run->processing_id,
            'step' => 'transcription',
            'status' => ProcessingStatus::Completed,
        ]);
        $segment = LivestreamSegment::factory()->forProcessingLog($run->id)->withIndex(7)->create();
        $childrensTalk = Sermon::factory()->create([
            'content_type' => SermonContentType::ChildrensTalk,
        ]);
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 2,
            'section_type' => ServiceSectionType::ChildrensTalk,
            'source_segment_ids' => [$segment->id],
            'published_sermon_id' => $childrensTalk->id,
        ]);
        $song = Song::factory()->create(['canonical_key' => 'amazing-grace']);
        SongVideo::factory()->create([
            'song_id' => $song->id,
            'service_section_id' => $section->id,
        ]);

        $first = app(HistoricProcessingResultInventory::class)->build($run);
        $second = app(HistoricProcessingResultInventory::class)->build($run->fresh());

        $this->assertSame($first['logical_hash'], $second['logical_hash']);
        $this->assertSame('historic-run:segment:7', $first['segments'][0]['segment_key']);
        $this->assertSame(
            ['historic-run:segment:7'],
            $first['sections'][0]['source_segment_keys'],
        );
        $this->assertSame('childrens_talk', $first['publications'][0]['content_type']);
        $this->assertSame('amazing-grace', $first['song_videos'][0]['song_canonical_key']);
        $this->assertArrayNotHasKey('owner_user_id', $first['run']);
        $this->assertArrayNotHasKey('queue_name', $first['run']);
        $this->assertArrayNotHasKey('owner_user_id', $first['metadata']);
        $this->assertArrayNotHasKey('queue_name', $first['metadata']);
    }

    #[Test]
    public function it_rejects_a_section_link_to_a_segment_outside_the_run(): void
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create();
        $otherRun = MediaProcessingLog::factory()->livestream()->completed()->create();
        $foreignSegment = LivestreamSegment::factory()->forProcessingLog($otherRun->id)->create();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'source_segment_ids' => [$foreignSegment->id],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside processing run');

        app(HistoricProcessingResultInventory::class)->build($run);
    }
}
