<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Jobs\RecordDeferredCorpusRerunMedia;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Support\WorkerCode;
use App\Models\ServiceSection;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecordDeferredCorpusRerunMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'media-processing.storage.temp_disk' => 'local',
            'media-processing.storage.transcript_disk' => 'local',
        ]);
    }

    /**
     * A song's review needs its span and evidence, not its clip, so a detection round decides
     * it; publishing needs the clip, so the publication status waits for the cut.
     */
    #[Test]
    public function it_decides_song_review_without_cutting_or_publishing(): void
    {
        $run = $this->roundRun();
        $song = $this->songSection($run, start: 515.0, end: 534.95);

        dispatch_sync(new RecordDeferredCorpusRerunMedia($run));

        $song->refresh();
        $reasons = array_column($song->metadata?->toArray()['song_publication_review']['reasons'] ?? [], 'kind');

        self::assertContains('short_song_clip', $reasons);
        self::assertSame(ServiceSectionPublicationStatus::NotApplicable, $song->publication_status);
        self::assertNull($song->extracted_video_path);
    }

    /**
     * The plan re-extraction would cut goes on the round's stamp. `sermon_extraction_plan`
     * records what *was* cut, so it keeps describing the media that exists.
     */
    #[Test]
    public function it_records_the_plan_it_would_cut_on_the_rounds_stamp_only(): void
    {
        $run = $this->roundRun();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 700.0,
            'end_time' => 2500.0,
            'duration' => 1800.0,
            'confidence' => 0.95,
        ]);

        dispatch_sync(new RecordDeferredCorpusRerunMedia($run));

        $run->refresh();
        $stamp = $run->corpusRerunStamps()[0];

        self::assertNotEmpty($stamp['deferred_extraction_plan']['segments'] ?? null);
        self::assertNotNull($stamp['media_recorded_at'] ?? null);
        self::assertSame(WorkerCode::bootCommit(), $stamp['worker_commit'] ?? 'missing', 'The round records the code its worker booted on.');
        // JSON storage returns whole-number floats as integers, so compare by value.
        self::assertEquals(
            [['start_time' => 600.0, 'end_time' => 2400.0]],
            $run->processing_metadata?->toArray()['sermon_extraction_plan']['segments'],
        );
        self::assertTrue($run->hasDeferredCorpusRerunMedia());
    }

    private function roundRun(): MediaProcessingLog
    {
        $churchService = ChurchService::factory()->create(['date' => '2026-03-15']);

        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $churchService->id,
            'sermon_start_time' => 600.0,
            'sermon_end_time' => 2400.0,
            'processing_metadata' => [
                'sermon_extraction_plan' => ['segments' => [['start_time' => 600.0, 'end_time' => 2400.0]]],
                'corpus_rerun' => [[
                    'grounds' => 'corpus_rerun',
                    'git_commit' => str_repeat('a', 40),
                    'media' => 'deferred',
                    'dispatched_at' => '2026-09-24T19:00:00+00:00',
                ]],
            ],
        ]);
    }

    private function songSection(MediaProcessingLog $run, float $start, float $end): ServiceSection
    {
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $run->church_service_id,
            'song_id' => Song::factory()->create()->id,
        ]);

        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
            'extracted_video_path' => null,
            'extracted_audio_path' => null,
            'start_time' => $start,
            'end_time' => $end,
            'duration' => $end - $start,
        ]);
    }
}
