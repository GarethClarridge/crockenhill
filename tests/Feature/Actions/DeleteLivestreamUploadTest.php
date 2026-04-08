<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\DeleteLivestreamUpload;
use App\Enums\ChurchServiceItemSource;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonContentType;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\SermonProcessingStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeleteLivestreamUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.transcript_disk' => 'local',
            'thumbnail-generation.storage.disk' => 'public',
        ]);
    }

    #[Test]
    public function it_deletes_a_broken_livestream_upload_and_removes_an_empty_projected_service(): void
    {
        $processingId = '11111111-1111-1111-1111-111111111111';
        $service = ChurchService::factory()->create([
            'date' => '2026-04-06',
            'service' => SermonService::MORNING->value,
            'source' => MediaType::Livestream->value,
            'needs_review' => true,
            'import_metadata' => [
                'livestream_projection' => [
                    'processing_id' => $processingId,
                ],
                'review_triggers' => ['manual_review_sections'],
            ],
        ]);

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'processing_id' => $processingId,
            'status' => ProcessingStatus::Completed,
            'church_service_id' => null,
            'source_file_path' => 'livestream/temp/'.$processingId.'.mp4',
            'rms_log_path' => 'livestream/temp/'.$processingId.'.json',
        ]);

        $sermon = Sermon::factory()->fromLivestream()->create([
            'livestream_processing_id' => $processingId,
            'date' => '2026-04-06',
            'service' => SermonService::MORNING->value,
            'content_type' => SermonContentType::Sermon,
            'audio_file_path' => 'sermons/audio/'.$processingId.'_sermon.mp3',
            'video_file_path' => 'sermons/771/video.mp4',
            'transcript_file_path' => 'transcripts/sermon_771.md',
            'thumbnail_file_path' => 'sermons/thumbnails/sermon_771_overlay.webp',
            'thumbnail_metadata' => [
                'plain_thumbnail_path' => 'sermons/thumbnails/sermon_771_plain.webp',
                'card_thumbnail_path' => 'sermons/thumbnails/sermon_771_card.webp',
                'overlay_thumbnail_path' => 'sermons/thumbnails/sermon_771_overlay.webp',
                'thumbnail_candidates' => [
                    [
                        'id' => 'candidate-1',
                        'timestamp' => 120.0,
                        'score' => 0.8,
                        'plain_path' => 'sermons/thumbnails/sermon_771_candidate_plain.webp',
                        'card_path' => 'sermons/thumbnails/sermon_771_candidate_card.webp',
                        'overlay_path' => 'sermons/thumbnails/sermon_771_candidate_overlay.webp',
                    ],
                ],
            ],
        ]);

        $log->update([
            'sermon_id' => $sermon->id,
            'audio_file_path' => $sermon->audio_file_path,
            'video_file_path' => $sermon->video_file_path,
            'transcript_file_path' => $sermon->transcript_file_path,
        ]);

        ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'source' => ChurchServiceItemSource::LIVESTREAM->value,
            'title' => 'Children\'s Talk',
            'metadata' => [
                'livestream_projection' => [
                    'processing_id' => $processingId,
                    'service_section_id' => 120,
                ],
            ],
        ]);

        SermonProcessingStep::factory()->completed()->create([
            'processing_id' => $processingId,
            'step' => 'transcribing',
        ]);

        Storage::disk('local')->put('livestream/temp/'.$processingId.'.mp4', 'video');
        Storage::disk('local')->put('livestream/temp/'.$processingId.'.json', '{}');
        Storage::disk('local')->put('transcripts/sermon_771.md', 'transcript');
        Storage::disk('public')->put('sermons/audio/'.$processingId.'_sermon.mp3', 'audio');
        Storage::disk('public')->put('sermons/771/video.mp4', 'video');
        Storage::disk('public')->put('sermons/thumbnails/sermon_771_overlay.webp', 'overlay');
        Storage::disk('public')->put('sermons/thumbnails/sermon_771_plain.webp', 'plain');
        Storage::disk('public')->put('sermons/thumbnails/sermon_771_card.webp', 'card');
        Storage::disk('public')->put('sermons/thumbnails/sermon_771_candidate_plain.webp', 'plain');
        Storage::disk('public')->put('sermons/thumbnails/sermon_771_candidate_card.webp', 'card');
        Storage::disk('public')->put('sermons/thumbnails/sermon_771_candidate_overlay.webp', 'overlay');

        $result = app(DeleteLivestreamUpload::class)->execute($log);

        $this->assertSame($processingId, $result['processing_id']);
        $this->assertSame(1, $result['deleted_sermons']);
        $this->assertSame(1, $result['deleted_projected_items']);
        $this->assertSame(1, $result['deleted_services']);
        $this->assertSame([$service->id], $result['deleted_service_ids']);

        $this->assertDatabaseMissing('media_processing_logs', ['id' => $log->id]);
        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);
        $this->assertDatabaseMissing('church_service_items', ['church_service_id' => $service->id]);
        $this->assertDatabaseMissing('sermon_processing_steps', ['processing_id' => $processingId]);
        $this->assertDatabaseMissing('church_services', ['id' => $service->id]);

        Storage::disk('local')->assertMissing('livestream/temp/'.$processingId.'.mp4');
        Storage::disk('local')->assertMissing('livestream/temp/'.$processingId.'.json');
        Storage::disk('local')->assertMissing('transcripts/sermon_771.md');
        Storage::disk('public')->assertMissing('sermons/audio/'.$processingId.'_sermon.mp3');
        Storage::disk('public')->assertMissing('sermons/771/video.mp4');
        Storage::disk('public')->assertMissing('sermons/thumbnails/sermon_771_overlay.webp');
        Storage::disk('public')->assertMissing('sermons/thumbnails/sermon_771_plain.webp');
        Storage::disk('public')->assertMissing('sermons/thumbnails/sermon_771_card.webp');
        Storage::disk('public')->assertMissing('sermons/thumbnails/sermon_771_candidate_plain.webp');
        Storage::disk('public')->assertMissing('sermons/thumbnails/sermon_771_candidate_card.webp');
        Storage::disk('public')->assertMissing('sermons/thumbnails/sermon_771_candidate_overlay.webp');
    }

    #[Test]
    public function it_only_removes_records_owned_by_the_selected_upload_when_the_service_should_remain(): void
    {
        $processingId = '22222222-2222-2222-2222-222222222222';
        $service = ChurchService::factory()->create([
            'date' => '2026-04-13',
            'service' => SermonService::MORNING->value,
            'source' => 'manual',
            'needs_review' => true,
            'import_metadata' => [
                'livestream_projection' => [
                    'processing_id' => $processingId,
                ],
            ],
        ]);

        $manualItem = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'source' => ChurchServiceItemSource::MANUAL->value,
            'title' => 'Welcome',
        ]);

        $runItem = ChurchServiceItem::factory()->livestream()->create([
            'church_service_id' => $service->id,
            'position' => 2,
            'source' => ChurchServiceItemSource::LIVESTREAM->value,
            'title' => 'Sermon',
            'metadata' => [
                'livestream_projection' => [
                    'processing_id' => $processingId,
                    'service_section_id' => 200,
                ],
            ],
        ]);

        $log = MediaProcessingLog::factory()->livestream()->failed()->create([
            'processing_id' => $processingId,
            'church_service_id' => $service->id,
        ]);

        $sermon = Sermon::factory()->fromLivestream()->create([
            'livestream_processing_id' => $processingId,
            'audio_file_path' => 'sermons/audio/'.$processingId.'_sermon.mp3',
        ]);

        $log->update([
            'sermon_id' => $sermon->id,
            'audio_file_path' => $sermon->audio_file_path,
        ]);

        Storage::disk('public')->put('sermons/audio/'.$processingId.'_sermon.mp3', 'audio');

        $result = app(DeleteLivestreamUpload::class)->execute($log);

        $this->assertSame(0, $result['deleted_services']);
        $this->assertDatabaseHas('church_services', ['id' => $service->id]);
        $this->assertDatabaseHas('church_service_items', ['id' => $manualItem->id]);
        $this->assertDatabaseMissing('church_service_items', ['id' => $runItem->id]);
        $this->assertDatabaseMissing('media_processing_logs', ['id' => $log->id]);
        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);

        $service->refresh();

        $this->assertArrayNotHasKey('livestream_projection', $service->import_metadata?->toArray() ?? []);
    }
}
