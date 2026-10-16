<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\HoldSectionForContentReview;
use App\Actions\ServiceReview\ConfirmServiceSection;
use App\Enums\ContentHoldCheck;
use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionPublicationStatus;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionStatus;
use App\Enums\ServiceSectionType;
use App\Enums\TalkType;
use App\Exceptions\UnplacedContentHoldException;
use App\Exceptions\UnplacedOutputEdgeAnswerException;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Models\SongVideo;
use App\Models\User;
use App\Services\ChurchService\OutputEdgeAnswers;
use App\Services\ChurchService\ServiceSectionSyncService;
use App\Services\ChurchService\TalkReadingPairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceSectionSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOLD_REASON = 'Saved text repeats a sentence the audio does not';

    private const HOLD_EVIDENCE = 'docs/plans/HISTORIC-VIDEO-DEFECT-DISCOVERY-AND-ACCEPTANCE-2026-08-29.md';

    private ServiceSectionSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ServiceSectionSyncService::class);
    }

    #[Test]
    public function inserting_a_section_detaches_stale_song_clips_from_later_non_song_rows(): void
    {
        Storage::fake('public');
        $run = MediaProcessingLog::factory()->livestream()->create();
        $sections = [];
        $videos = [];

        foreach ([ServiceSectionType::Other, ServiceSectionType::Sermon] as $index => $type) {
            $section = ServiceSection::factory()->create([
                'media_processing_log_id' => $run->id,
                'church_service_item_id' => null,
                'section_order' => $index + 1,
                'section_type' => $type,
                'title' => $index === 0 ? 'Introduction to the next song' : 'Sermon',
                'start_time' => 100.0 + $index * 100,
                'end_time' => 121.0 + $index * 100,
                'extracted_video_path' => "sermons/songs/stale-{$index}.mp4",
            ]);
            $videos[] = SongVideo::factory()->quarantined()->create([
                'service_section_id' => $section->id,
                'video_file_path' => $section->extracted_video_path,
            ]);
            Storage::disk('public')->put($section->extracted_video_path, 'preserved clip');
            $sections[] = $section;
        }

        $incoming = [$this->sectionData(null, 1, ServiceSectionType::Prayer->value, 'Inserted prayer', 0.0, 60.0, 60.0)];
        foreach ($sections as $index => $section) {
            $incoming[] = $this->sectionData(null, $index + 2, $section->section_type->value, $section->title, $section->start_time, $section->end_time, $section->duration);
        }
        $this->service->sync($run, $incoming);

        foreach ($sections as $index => $section) {
            $this->assertSame($index + 2, $section->fresh()->section_order);
            $this->assertNull($section->fresh()->extracted_video_path);
            $this->assertNull($videos[$index]->fresh()->service_section_id);
            Storage::disk('public')->assertExists($videos[$index]->video_file_path);
        }
    }

    /** @return array<string, array{bool}> */
    public static function removalRoutes(): array
    {
        return ['sync' => [false], 'explicit removal' => [true]];
    }

    #[Test]
    #[DataProvider('removalRoutes')]
    public function removing_a_non_song_section_quarantines_and_detaches_its_stale_video(bool $explicit): void
    {
        Storage::fake('public');
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Prayer,
            'extracted_video_path' => 'sermons/songs/stale.mp4',
        ]);
        $video = SongVideo::factory()->create([
            'service_section_id' => $section->id,
            'video_file_path' => $section->extracted_video_path,
        ]);
        Storage::disk('public')->put($video->video_file_path, 'preserved clip');

        if ($explicit) {
            $this->service->removeSection($section);
        } else {
            $this->service->sync($run, []);
        }

        $this->assertModelMissing($section);
        $this->assertNull($video->fresh()->service_section_id);
        $this->assertSame(SermonPublicationState::Quarantined, $video->fresh()->publication_state);
        Storage::disk('public')->assertExists($video->video_file_path);
    }

    /** @return array<string, array{string, float, bool}> */
    public static function changedSongSections(): array
    {
        return [
            'moved span' => [ServiceSectionType::Song->value, 65.0, false],
            'retyped section' => [ServiceSectionType::Prayer->value, 60.0, false],
            'rollback' => [ServiceSectionType::Song->value, 65.0, true],
        ];
    }

    #[Test]
    #[DataProvider('changedSongSections')]
    public function changing_a_song_detaches_its_old_video_without_deleting_it(string $type, float $start, bool $rollback): void
    {
        Storage::fake('public');
        $run = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'church_service_item_id' => null,
            'section_order' => 1,
            'section_type' => ServiceSectionType::Song,
            'title' => 'Song',
            'start_time' => 60.0,
            'end_time' => 180.0,
            'extracted_video_path' => 'sermons/songs/old.mp4',
        ]);
        $video = SongVideo::factory()->create([
            'service_section_id' => $section->id,
            'video_file_path' => $section->extracted_video_path,
        ]);
        Storage::disk('public')->put($video->video_file_path, 'preserved clip');

        $incoming = [$this->sectionData(null, 1, $type, 'Song', $start, 180.0, 180.0 - $start)];
        if ($rollback) {
            $incoming[] = $this->sectionData(null, 2, 'invalid_type');
        }

        try {
            $this->service->sync($run, $incoming);
            $this->assertFalse($rollback, 'Invalid sections must refuse the sync.');
        } catch (ValidationException $exception) {
            if (! $rollback) {
                throw $exception;
            }

            $this->assertSame($section->id, $video->fresh()->service_section_id);
            $this->assertSame(SermonPublicationState::Published, $video->fresh()->publication_state);
            $this->assertSame($video->video_file_path, $section->fresh()->extracted_video_path);
            Storage::disk('public')->assertExists($video->video_file_path);

            return;
        }

        $this->assertNull($section->fresh()->extracted_video_path);
        $this->assertNull($video->fresh()->service_section_id);
        $this->assertSame(SermonPublicationState::Quarantined, $video->fresh()->publication_state);
        Storage::disk('public')->assertExists($video->video_file_path);
    }

    #[Test]
    public function it_creates_service_sections_for_first_sync(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $itemOne = ChurchServiceItem::factory()->create();
        $itemTwo = ChurchServiceItem::factory()->create();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Song->value,
                title: 'Song One'
            ),
            $this->sectionData(
                churchServiceItemId: $itemTwo->id,
                sectionOrder: 2,
                sectionType: ServiceSectionType::Prayer->value,
                title: 'Opening Prayer'
            ),
        ]);

        $this->assertSame(2, $processingLog->serviceSections()->count());
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 1,
            'section_type' => ServiceSectionType::Song->value,
            'title' => 'Song One',
        ]);
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 2,
            'section_type' => ServiceSectionType::Prayer->value,
            'title' => 'Opening Prayer',
        ]);
    }

    #[Test]
    public function it_updates_existing_rows_and_replaces_removed_rows_idempotently(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $itemOne = ChurchServiceItem::factory()->create();
        $itemTwo = ChurchServiceItem::factory()->create();
        $itemThree = ChurchServiceItem::factory()->create();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Song->value,
                title: 'Song One'
            ),
            $this->sectionData(
                churchServiceItemId: $itemTwo->id,
                sectionOrder: 2,
                sectionType: ServiceSectionType::Notices->value,
                title: 'Notices'
            ),
        ]);

        $originalOrderOne = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 1)
            ->firstOrFail();

        $originalOrderTwo = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 2)
            ->firstOrFail();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Song->value,
                title: 'Song One (Updated)',
                startTime: 120.0,
                endTime: 330.0,
                duration: 210.0
            ),
            $this->sectionData(
                churchServiceItemId: $itemThree->id,
                sectionOrder: 3,
                sectionType: ServiceSectionType::Prayer->value,
                title: 'Closing Prayer'
            ),
        ]);

        $updatedOrderOne = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 1)
            ->firstOrFail();

        $newOrderThree = ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', 3)
            ->firstOrFail();

        $this->assertSame($originalOrderOne->id, $updatedOrderOne->id);
        $this->assertSame('Song One (Updated)', $updatedOrderOne->title);
        $this->assertSame(120.0, $updatedOrderOne->start_time);
        $this->assertSame(330.0, $updatedOrderOne->end_time);

        $this->assertDatabaseMissing('service_sections', ['id' => $originalOrderTwo->id]);
        $this->assertSame('Closing Prayer', $newOrderThree->title);
        $this->assertSame(2, $processingLog->serviceSections()->count());
    }

    #[Test]
    public function it_supersedes_published_link_keeping_its_media_and_resets_publishable_rows_when_signature_changes(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.temp_disk' => 'local',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $churchServiceItem = ChurchServiceItem::factory()->create();
        $publishedSermon = Sermon::factory()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $churchServiceItem->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'section_order' => 1,
            'title' => 'Children Talk',
            'start_time' => 120.0,
            'end_time' => 360.0,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'published_sermon_id' => $publishedSermon->id,
            'published_at' => now(),
            'extracted_video_path' => 'sermons/sections/'.$churchServiceItem->id.'/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$churchServiceItem->id.'.mp3',
            'extracted_at' => now(),
        ]);

        Storage::disk('public')->put((string) $section->extracted_video_path, 'video');
        Storage::disk('public')->put((string) $section->extracted_audio_path, 'audio');

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $churchServiceItem->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::ShortTalk->value,
                title: 'Children Talk Updated',
                startTime: 130.0,
                endTime: 390.0,
                duration: 260.0
            ),
        ]);

        $section->refresh();
        $metadata = $section->metadata?->toArray() ?? [];

        $this->assertSame(ServiceSectionPublicationStatus::NotApplicable, $section->publication_status);
        $this->assertNull($section->published_sermon_id);
        $this->assertNull($section->published_at);
        $this->assertNull($section->extracted_video_path);
        $this->assertNull($section->extracted_audio_path);
        $this->assertNull($section->unpublished_expires_at);
        $this->assertArrayHasKey('superseded', $metadata);
        $this->assertTrue((bool) ($metadata['publishable_type_after_supersede'] ?? false));
        // The sermon outlives the link and plays from these files, so they stay.
        $this->assertDatabaseHas('sermons', ['id' => $publishedSermon->id]);
        Storage::disk('public')->assertExists('sermons/sections/'.$churchServiceItem->id.'/video.mp4');
        Storage::disk('public')->assertExists('sermons/audio/section-'.$churchServiceItem->id.'.mp3');
    }

    /**
     * Canary 9, run 1221: re-detection respelt "Speak O Lord" as "Speak, O Lord" on the same
     * order-of-service item and span, and the sync deleted the published clip and its song video.
     */
    #[Test]
    public function a_bound_section_whose_title_is_only_respelt_keeps_its_media_and_publication(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 12,
            'title' => 'Speak O Lord',
            'start_time' => 2248.0,
            'end_time' => 2440.03,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'extracted_video_path' => 'section-publications/2727/video.mp4',
            'extracted_at' => now(),
        ]);
        $songVideo = SongVideo::factory()->create([
            'service_section_id' => $section->id,
            'video_file_path' => 'sermons/songs/2727.mp4',
        ]);
        Storage::disk('public')->put('section-publications/2727/video.mp4', 'video');
        Storage::disk('public')->put('sermons/songs/2727.mp4', 'video');

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $item->id,
                sectionOrder: 12,
                sectionType: ServiceSectionType::Song->value,
                title: 'Speak, O Lord',
                startTime: 2248.0,
                endTime: 2440.03,
                duration: 192.03,
            ),
        ]);

        $section->refresh();

        $this->assertSame('Speak, O Lord', $section->title);
        $this->assertSame(ServiceSectionPublicationStatus::Published, $section->publication_status);
        $this->assertSame('section-publications/2727/video.mp4', $section->extracted_video_path);
        $this->assertArrayNotHasKey('superseded', $section->metadata?->toArray() ?? []);
        $this->assertModelExists($songVideo);
        $this->assertSame($section->id, $songVideo->fresh()->service_section_id);
        Storage::disk('public')->assertExists('section-publications/2727/video.mp4');
        Storage::disk('public')->assertExists('sermons/songs/2727.mp4');
    }

    /** Canary 9, run 1221: "King Of Kings Majesty" → "King of Kings Majesty", here with no item bound. */
    #[Test]
    public function an_unbound_section_whose_title_differs_only_in_case_and_punctuation_keeps_its_media(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 3,
            'title' => 'King Of Kings Majesty',
            'start_time' => 114.11,
            'end_time' => 251.06,
            'extracted_video_path' => 'section-publications/2718/video.mp4',
            'extracted_at' => now(),
        ]);
        Storage::disk('public')->put('section-publications/2718/video.mp4', 'video');

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: null,
                sectionOrder: 3,
                sectionType: ServiceSectionType::Song->value,
                title: 'King of Kings, Majesty',
                startTime: 114.11,
                endTime: 251.06,
                duration: 136.95,
            ),
        ]);

        $this->assertSame('section-publications/2718/video.mp4', $section->fresh()->extracted_video_path);
        Storage::disk('public')->assertExists('section-publications/2718/video.mp4');
    }

    /** A section inserted or removed earlier in the service no longer shifts a clip onto its neighbour's row. */
    #[Test]
    public function an_unchanged_section_keeps_its_media_when_an_earlier_section_is_inserted_or_removed(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create();
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'title' => 'Welcome',
            'start_time' => 0.0,
            'end_time' => 100.0,
        ]);
        $song = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'title' => 'More About Jesus',
            'start_time' => 1753.0,
            'end_time' => 1937.24,
            'extracted_video_path' => 'section-publications/1398/video.mp4',
            'extracted_at' => now(),
        ]);
        Storage::disk('public')->put('section-publications/1398/video.mp4', 'video');
        $welcome = $this->sectionData(null, 1, ServiceSectionType::Welcome->value, 'Welcome', 0.0, 100.0, 100.0);
        $prayer = $this->sectionData(null, 2, ServiceSectionType::Prayer->value, 'Prayer', 100.0, 200.0, 100.0);
        $songAt = fn (int $order): array => $this->sectionData($item->id, $order, ServiceSectionType::Song->value, 'More About Jesus', 1753.0, 1937.24, 184.24);

        $this->service->sync($processingLog, [$welcome, $prayer, $songAt(3)]);

        $song->refresh();
        $this->assertSame(3, $song->section_order);
        $this->assertSame('section-publications/1398/video.mp4', $song->extracted_video_path);
        $this->assertSame(3, ServiceSection::query()->where('media_processing_log_id', $processingLog->id)->count());

        $this->service->sync($processingLog, [$songAt(1)]);

        $song->refresh();
        $this->assertSame(1, $song->section_order);
        $this->assertSame('section-publications/1398/video.mp4', $song->extracted_video_path);
        $this->assertSame(1, ServiceSection::query()->where('media_processing_log_id', $processingLog->id)->count());
        Storage::disk('public')->assertExists('section-publications/1398/video.mp4');
    }

    #[Test]
    public function an_unbound_section_given_a_different_title_is_still_superseded(): void
    {
        Storage::fake('public');
        config(['media-processing.storage.sermon_disk' => 'public']);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 3,
            'title' => 'King Of Kings Majesty',
            'start_time' => 114.11,
            'end_time' => 251.06,
            'extracted_video_path' => 'section-publications/2718/video.mp4',
            'extracted_at' => now(),
        ]);
        Storage::disk('public')->put('section-publications/2718/video.mp4', 'video');

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: null,
                sectionOrder: 3,
                sectionType: ServiceSectionType::Song->value,
                title: 'Majesty',
                startTime: 114.11,
                endTime: 251.06,
                duration: 136.95,
            ),
        ]);

        $this->assertNull($section->fresh()->extracted_video_path);
        Storage::disk('public')->assertMissing('section-publications/2718/video.mp4');
    }

    #[Test]
    public function it_removes_stale_rows_after_detaching_published_links_keeping_their_media(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        config([
            'media-processing.storage.sermon_disk' => 'public',
            'media-processing.storage.temp_disk' => 'local',
        ]);

        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $itemOne = ChurchServiceItem::factory()->create();
        $itemTwo = ChurchServiceItem::factory()->create();
        $publishedSermon = Sermon::factory()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $itemOne->id,
            'section_type' => ServiceSectionType::Welcome->value,
            'section_order' => 1,
            'publication_status' => ServiceSectionPublicationStatus::NotApplicable->value,
        ]);

        $stalePublished = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $itemTwo->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'section_order' => 2,
            'publication_status' => ServiceSectionPublicationStatus::Published->value,
            'published_sermon_id' => $publishedSermon->id,
            'extracted_video_path' => 'sermons/sections/'.$itemTwo->id.'/video.mp4',
            'extracted_audio_path' => 'sermons/audio/section-'.$itemTwo->id.'.mp3',
        ]);

        Storage::disk('public')->put('sermons/sections/'.$itemTwo->id.'/video.mp4', 'video');
        Storage::disk('public')->put('sermons/audio/section-'.$itemTwo->id.'.mp3', 'audio');

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $itemOne->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Welcome->value,
                title: 'Welcome'
            ),
        ]);

        $this->assertDatabaseMissing('service_sections', ['id' => $stalePublished->id]);
        // The sermon outlives the row and plays from these files, so they stay.
        $this->assertDatabaseHas('sermons', ['id' => $publishedSermon->id]);
        Storage::disk('public')->assertExists('sermons/sections/'.$itemTwo->id.'/video.mp4');
        Storage::disk('public')->assertExists('sermons/audio/section-'.$itemTwo->id.'.mp3');
    }

    #[Test]
    public function it_allows_sections_without_a_church_service_item_reference(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: null,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Sermon->value,
                startTime: 300.0,
                endTime: 1800.0,
                duration: 1500.0,
                needsManualReview: false,
                classificationMode: 'audio_only'
            ),
        ]);

        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $processingLog->id,
            'section_order' => 1,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon->value,
        ]);
    }

    #[Test]
    public function it_preserves_existing_transcript_metadata_when_the_section_signature_is_unchanged(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $item = ChurchServiceItem::factory()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => $item->id,
            'section_type' => ServiceSectionType::Prayer->value,
            'section_order' => 1,
            'title' => 'Opening Prayer',
            'start_time' => 60.0,
            'end_time' => 180.0,
            'duration' => 120.0,
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => 'openlp_aligned',
                'transcript' => 'Existing section transcript',
            ],
        ]);

        $this->service->sync($processingLog, [
            $this->sectionData(
                churchServiceItemId: $item->id,
                sectionOrder: 1,
                sectionType: ServiceSectionType::Prayer->value,
                title: 'Opening Prayer'
            ),
        ]);

        $section->refresh();

        $this->assertSame('Existing section transcript', $section->metadata['transcript'] ?? null);
        $this->assertSame('openlp_aligned', $section->metadata['classification_mode'] ?? null);
    }

    #[Test]
    public function a_re_detection_refreshes_the_talk_type_proposal_but_keeps_the_reviewed_type(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $reviewed = ['value' => 'testimony', 'user_id' => 1, 'at' => '2026-09-23T20:00:00+00:00'];

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'section_type' => ServiceSectionType::ShortTalk->value,
            'section_order' => 1,
            'title' => 'Mission update',
            'church_service_item_id' => null,
            'start_time' => 60.0,
            'end_time' => 180.0,
            'duration' => 120.0,
            'metadata' => ['talk_type' => ['proposed' => 'childrens_talk', 'reviewed' => $reviewed]],
        ]);

        $incoming = $this->sectionData(null, 1, ServiceSectionType::ShortTalk->value, 'Mission update');
        $incoming['metadata']['talk_type'] = ['proposed' => 'partner_update'];
        $this->service->sync($processingLog, [$incoming]);

        $talkType = $section->refresh()->metadata?->talkType;

        $this->assertSame(TalkType::PartnerUpdate, $talkType?->proposed);
        $this->assertSame(TalkType::Testimony, $talkType?->publicationTalkType());
    }

    /** Whether a talk is about its reading answers for the talk, not its edges (operator ruling 2026-10-13). */
    #[Test]
    public function a_talk_re_detected_with_moved_edges_keeps_its_reading_answer_and_a_new_section_does_not(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $answer = ['include' => true, 'reading_section_id' => 1, 'answered_by' => 'talk page'];
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id, 'section_type' => ServiceSectionType::ShortTalk->value, 'section_order' => 1,
            'title' => 'Jehovah-Nissi', 'church_service_item_id' => null, 'start_time' => 60.0, 'end_time' => 180.0, 'duration' => 120.0,
            'metadata' => [TalkReadingPairing::METADATA_KEY => $answer],
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id, 'section_type' => ServiceSectionType::ShortTalk->value, 'section_order' => 2,
            'title' => 'Mary Slessor', 'church_service_item_id' => null, 'start_time' => 300.0, 'end_time' => 400.0, 'duration' => 100.0,
            'metadata' => [TalkReadingPairing::METADATA_KEY => $answer],
        ]);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::ShortTalk->value, 'Jehovah-Nissi', 62.5, 181.0, 118.5),
            $this->sectionData(null, 2, ServiceSectionType::Prayer->value, 'Prayer', 300.0, 400.0, 100.0),
        ]);

        $this->assertEquals($answer, $this->sectionAt($processingLog, 1)->metadata->raw[TalkReadingPairing::METADATA_KEY] ?? null);
        $this->assertArrayNotHasKey(TalkReadingPairing::METADATA_KEY, $this->sectionAt($processingLog, 2)->metadata->raw);
    }

    /** Codex review 2026-10-13: a section inserted before the talk while its edges move pairs the talk with no stored row by identity or order. */
    #[Test]
    public function a_talks_reading_answer_follows_it_to_a_new_order(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $answer = ['include' => true, 'reading_section_id' => 1, 'answered_by' => 'talk page'];
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id, 'section_type' => ServiceSectionType::BibleReading->value, 'section_order' => 1,
            'title' => 'Exodus 17', 'church_service_item_id' => null, 'start_time' => 0.0, 'end_time' => 50.0, 'duration' => 50.0,
        ]);
        ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id, 'section_type' => ServiceSectionType::ShortTalk->value, 'section_order' => 2,
            'title' => 'Jehovah-Nissi', 'church_service_item_id' => null, 'start_time' => 60.0, 'end_time' => 180.0, 'duration' => 120.0,
            'metadata' => [TalkReadingPairing::METADATA_KEY => $answer],
        ]);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::BibleReading->value, 'Exodus 17', 0.0, 50.0, 50.0),
            $this->sectionData(null, 2, ServiceSectionType::Other->value, 'Notice', 50.0, 58.0, 8.0),
            $this->sectionData(null, 3, ServiceSectionType::ShortTalk->value, 'Jehovah-Nissi', 58.0, 181.0, 123.0),
        ]);

        $this->assertEquals($answer, $this->sectionAt($processingLog, 3)->metadata->raw[TalkReadingPairing::METADATA_KEY] ?? null);
        $this->assertArrayNotHasKey(TalkReadingPairing::METADATA_KEY, $this->sectionAt($processingLog, 2)->metadata->raw);
    }

    #[Test]
    public function a_re_detection_hands_an_unchanged_song_back_to_matching(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->boundSongSection($processingLog, 'God of Glory');

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, 'God of Glory'),
        ]);

        $this->assertBindingDropped($section->refresh());
        $this->assertSame('Existing song transcript', $section->metadata['transcript'] ?? null);
    }

    #[Test]
    public function a_re_detection_that_changes_a_song_hands_it_back_to_matching(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->boundSongSection($processingLog, 'Almighty Lord Most High Draw Near #823');

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, 'God of Glory'),
        ]);

        $section->refresh();

        $this->assertArrayHasKey('superseded', $section->metadata?->toArray() ?? []);
        $this->assertBindingDropped($section);
    }

    #[Test]
    public function a_re_detection_keeps_a_song_match_a_person_reviewed(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->boundSongSection($processingLog, 'God of Glory', [
            'manual_review' => ['song_match_reviewed_at' => '2026-09-20T10:00:00+00:00', 'song_match_reviewed_by_user_id' => 1],
        ]);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, 'God of Glory'),
        ]);

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::Confirmed, $section->song_match_type);
        $this->assertSame(75, $section->metadata['transcript_song_match']['song_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    private function boundSongSection(MediaProcessingLog $processingLog, string $title, array $extraMetadata = []): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 1,
            'title' => $title,
            'start_time' => 60.0,
            'end_time' => 180.0,
            'duration' => 120.0,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'metadata' => array_merge([
                'transcript' => 'Existing song transcript',
                'song_title_hint' => 'God of Glory',
                'transcript_song_match' => ['song_id' => 75, 'title' => 'Almighty Lord Most High Draw Near #823', 'confidence' => 1.0, 'match_source' => 'title_hint_fuzzy'],
                'identity_sources' => ['heard'],
                'lyric_identity_check' => ['verdict' => 'unavailable', 'bound_song_id' => 75],
            ], $extraMetadata),
        ]);
    }

    private function assertBindingDropped(ServiceSection $section): void
    {
        $metadata = $section->metadata?->toArray() ?? [];

        $this->assertNull($section->song_match_type, 'A binding made for the old detection must be re-derived by matching.');
        $this->assertArrayNotHasKey('transcript_song_match', $metadata);
        $this->assertArrayNotHasKey('identity_sources', $metadata);
        $this->assertArrayNotHasKey('lyric_identity_check', $metadata);
    }

    #[Test]
    public function a_re_detection_keeps_the_content_hold_on_an_unchanged_section(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 1, startTime: 600.0, endTime: 1800.0);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Sermon->value, startTime: 600.0, endTime: 1800.0, duration: 1200.0),
        ]);

        $this->assertHeld($section->refresh());
    }

    #[Test]
    public function a_re_detection_that_moves_the_held_section_keeps_its_content_hold(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 1, startTime: 300.0, endTime: 500.0);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 290.0, endTime: 520.0, duration: 230.0),
        ]);

        $section->refresh();

        $this->assertArrayHasKey('superseded', $section->metadata?->toArray() ?? []);
        $this->assertHeld($section);
    }

    /**
     * Rows are kept by order, so after a re-detection shifts the orders the held
     * row can hold different content. The hold follows its content instead.
     */
    #[Test]
    public function a_content_hold_follows_its_content_to_a_new_order(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 2, startTime: 600.0, endTime: 1800.0);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Welcome->value, startTime: 0.0, endTime: 50.0, duration: 50.0),
            $this->sectionData(null, 2, ServiceSectionType::Song->value, startTime: 60.0, endTime: 180.0, duration: 120.0),
            $this->sectionData(null, 3, ServiceSectionType::Sermon->value, startTime: 610.0, endTime: 1790.0, duration: 1180.0),
        ]);

        $this->assertHeld($this->sectionAt($processingLog, 3));
        $this->assertNotHeld($this->sectionAt($processingLog, 2));
    }

    #[Test]
    public function a_content_hold_is_not_spread_to_content_it_does_not_cover(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 1, startTime: 300.0, endTime: 500.0);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 300.0, endTime: 500.0, duration: 200.0),
            $this->sectionData(null, 2, ServiceSectionType::Song->value, startTime: 600.0, endTime: 800.0, duration: 200.0),
            $this->sectionData(null, 3, ServiceSectionType::Prayer->value, startTime: 400.0, endTime: 450.0, duration: 50.0),
        ]);

        $this->assertHeld($this->sectionAt($processingLog, 1));
        $this->assertNotHeld($this->sectionAt($processingLog, 2));
        $this->assertNotHeld($this->sectionAt($processingLog, 3));
    }

    /**
     * Only an operator releases a hold. A re-detection that no longer finds the
     * held content cannot say whether the defect went with it, so it refuses to
     * write sections rather than let the hold vanish.
     */
    #[Test]
    public function a_re_detection_that_leaves_a_content_hold_nowhere_to_go_writes_nothing(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 1, startTime: 600.0, endTime: 1800.0);

        try {
            $this->service->sync($processingLog, [
                $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 60.0, endTime: 180.0, duration: 120.0),
            ]);
            $this->fail('A re-detection dropped a content hold.');
        } catch (UnplacedContentHoldException $exception) {
            $this->assertStringContainsString("section {$section->id}", $exception->getMessage());
        }

        $section->refresh();

        $this->assertSame(ServiceSectionType::Sermon, $section->section_type);
        $this->assertHeld($section);
        $this->assertSame(1, $processingLog->serviceSections()->count());
    }

    #[Test]
    public function a_released_content_hold_stays_released_and_keeps_its_history(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 1, startTime: 600.0, endTime: 1800.0);
        app(ConfirmServiceSection::class)->execute($section->refresh(), User::factory()->create()->id);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Sermon->value, startTime: 590.0, endTime: 1810.0, duration: 1220.0),
        ]);

        $section->refresh();

        $this->assertNotHeld($section);
        $this->assertCount(1, $section->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY] ?? []);
    }

    /**
     * A released hold refuses nothing, so a re-detection may leave its content
     * uncovered — but the history of what was found and decided there is kept on the
     * run rather than deleted with the row.
     */
    #[Test]
    public function released_hold_history_for_content_no_section_covers_is_kept_on_the_run(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 1, startTime: 600.0, endTime: 1800.0);
        app(ConfirmServiceSection::class)->execute($section->refresh(), User::factory()->create()->id);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 60.0, endTime: 180.0, duration: 120.0),
        ]);

        $unplaced = $processingLog->refresh()->processing_metadata?->toArray()['unplaced_content_hold_records'] ?? [];

        $this->assertCount(1, $unplaced);
        $this->assertSame($section->id, $unplaced[0]['section_id'] ?? null);
        $this->assertSame('sermon', $unplaced[0]['section_type'] ?? null);
        $this->assertSame(self::HOLD_REASON, $unplaced[0]['holds'][0]['reason'] ?? null);
        $this->assertNotEmpty($unplaced[0]['recorded_at'] ?? null);
    }

    /**
     * A re-detection can merge as well as move: the 09-17 song trim turned two
     * detected songs into one, and the canary only proved holds carry when the
     * replacement is one-for-one. Both holds must land on the surviving row —
     * losing either would release content an operator proved wrong.
     */
    #[Test]
    public function a_merge_into_one_section_carries_every_hold_it_covers(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $first = $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 1, startTime: 300.0, endTime: 420.0);
        $second = $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 2, startTime: 430.0, endTime: 560.0);
        app(HoldSectionForContentReview::class)($second, 'Second song is misidentified', 'canary-20260917-merge', ContentHoldCheck::Judgement);

        // One song now covers the span both held sections occupied.
        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 300.0, endTime: 560.0, duration: 260.0),
        ]);

        $this->assertSame(1, $processingLog->serviceSections()->count());

        $merged = $this->sectionAt($processingLog, 1);
        $this->assertHeld($merged);

        $reasons = array_column(
            $merged->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY] ?? [],
            'reason'
        );
        $this->assertContains(self::HOLD_REASON, $reasons, "The first section's hold was lost in the merge.");
        $this->assertContains('Second song is misidentified', $reasons, "The second section's hold was lost in the merge.");
        $this->assertCount(2, $reasons, 'Each covered hold is recorded once.');

        $this->assertDatabaseMissing('service_sections', ['id' => $second->id]);
        unset($first);
    }

    /**
     * The other direction: a split leaves a hold covering two rows. Holding both
     * is the safe error — an operator can release what turns out to be sound,
     * but nothing can recover content released while still wrong.
     */
    #[Test]
    public function a_split_holds_every_section_the_held_content_still_covers(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 1, startTime: 300.0, endTime: 560.0);

        // The macro song splits into the two real songs inside it.
        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 300.0, endTime: 420.0, duration: 120.0),
            $this->sectionData(null, 2, ServiceSectionType::Song->value, startTime: 430.0, endTime: 560.0, duration: 130.0),
        ]);

        $this->assertHeld($this->sectionAt($processingLog, 1));
        $this->assertHeld($this->sectionAt($processingLog, 2));
    }

    /**
     * Run 1287, 2026-09-23: re-detection trimmed the held song and inserted a
     * reading after it, and the hold's record stayed on the row at the old order —
     * the reading — describing a defect the reading never had.
     */
    #[Test]
    public function hold_records_follow_their_content_not_the_row_they_were_on(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $this->heldSection($processingLog, ServiceSectionType::Song, sectionOrder: 1, startTime: 190.0, endTime: 297.0);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 189.6, endTime: 277.0, duration: 87.4),
            $this->sectionData(null, 2, ServiceSectionType::BibleReading->value, startTime: 277.0, endTime: 298.0, duration: 21.0),
        ]);

        $this->assertHeld($this->sectionAt($processingLog, 1));

        $reading = $this->sectionAt($processingLog, 2);
        $this->assertNotHeld($reading);
        $this->assertArrayNotHasKey(
            HoldSectionForContentReview::METADATA_KEY,
            $reading->metadata?->toArray() ?? [],
            'The reading never carried this content; its record must not stay behind on the row.',
        );
    }

    #[Test]
    public function a_released_hold_record_moves_with_its_content_and_stays_released(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $sermon = $this->heldSection($processingLog, ServiceSectionType::Sermon, sectionOrder: 1, startTime: 600.0, endTime: 1800.0);
        app(ConfirmServiceSection::class)->execute($sermon->refresh(), User::factory()->create()->id);

        // A prayer is inserted before the sermon, so the sermon moves to order 2.
        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Prayer->value, startTime: 500.0, endTime: 590.0, duration: 90.0),
            $this->sectionData(null, 2, ServiceSectionType::Sermon->value, startTime: 600.0, endTime: 1800.0, duration: 1200.0),
        ]);

        $this->assertArrayNotHasKey(HoldSectionForContentReview::METADATA_KEY, $this->sectionAt($processingLog, 1)->metadata?->toArray() ?? []);

        $moved = $this->sectionAt($processingLog, 2);
        $this->assertNotHeld($moved);
        $this->assertCount(1, $moved->metadata?->toArray()[HoldSectionForContentReview::METADATA_KEY] ?? []);
    }

    /**
     * An operator's "end here" follows its sermon, as a content hold does: re-detection that adds
     * sections before it leaves the sermon on another row.
     */
    #[Test]
    public function an_output_edge_follows_its_sermon_to_a_new_order(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $this->sectionWithOutputEdge($processingLog, sectionOrder: 2, startTime: 600.0, endTime: 1800.0, end: 1795.4);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Welcome->value, startTime: 0.0, endTime: 50.0, duration: 50.0),
            $this->sectionData(null, 2, ServiceSectionType::Song->value, startTime: 60.0, endTime: 180.0, duration: 120.0),
            $this->sectionData(null, 3, ServiceSectionType::Sermon->value, startTime: 610.0, endTime: 1790.0, duration: 1180.0),
        ]);

        $this->assertSame(1795.4, OutputEdgeAnswers::recordsOf($this->sectionAt($processingLog, 3))['end']['time']);
        $this->assertSame([], OutputEdgeAnswers::recordsOf($this->sectionAt($processingLog, 2)));
    }

    #[Test]
    public function an_unchanged_sermon_keeps_its_output_edge(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $this->sectionWithOutputEdge($processingLog, sectionOrder: 1, startTime: 600.0, endTime: 1800.0, end: 1795.4);

        $this->service->sync($processingLog, [
            $this->sectionData(null, 1, ServiceSectionType::Sermon->value, startTime: 600.0, endTime: 1800.0, duration: 1200.0),
        ]);

        $this->assertSame(1795.4, OutputEdgeAnswers::recordsOf($this->sectionAt($processingLog, 1))['end']['time']);
    }

    /** Nothing of the sermon's type covers it any more: the answer is not dropped, the sync is refused. */
    #[Test]
    public function a_re_detection_that_leaves_an_output_edge_nowhere_to_go_writes_nothing(): void
    {
        $processingLog = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->sectionWithOutputEdge($processingLog, sectionOrder: 1, startTime: 600.0, endTime: 1800.0, end: 1795.4);

        try {
            $this->service->sync($processingLog, [
                $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 60.0, endTime: 180.0, duration: 120.0),
            ]);
            $this->fail('A re-detection dropped an operator\'s output edge.');
        } catch (UnplacedContentHoldException $exception) {
            $this->assertInstanceOf(UnplacedOutputEdgeAnswerException::class, $exception);
            $this->assertStringContainsString("section {$section->id}", $exception->getMessage());
            $this->assertSame([$section->id], $exception->unplacedSectionIds());
        }

        $this->assertSame(1795.4, OutputEdgeAnswers::recordsOf($section->fresh())['end']['time']);
        $this->assertSame(1, $processingLog->serviceSections()->count());
    }

    #[Test]
    public function cleared_edge_history_does_not_block_a_changed_section_type(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->sectionWithOutputEdge($log, 1, 600.0, 1800.0, 1795.4);
        $answers = $section->metadata->raw[OutputEdgeAnswers::METADATA_KEY];
        $section->update(['metadata' => [OutputEdgeAnswers::METADATA_KEY => ['history' => [$answers['end']]]]]);

        $this->service->sync($log, [
            $this->sectionData(null, 1, ServiceSectionType::Song->value, startTime: 600.0, endTime: 1800.0, duration: 1200.0),
        ]);

        $this->assertSame(ServiceSectionType::Song, $this->sectionAt($log, 1)->section_type);
    }

    #[Test]
    public function merging_answered_sections_refuses_before_overwriting_either_answer(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $first = $this->sectionWithOutputEdge($log, 1, 600.0, 1200.0, 1195.4);
        $second = $this->sectionWithOutputEdge($log, 2, 1200.0, 1800.0, 1795.4);

        try {
            $this->service->sync($log, [
                $this->sectionData(null, 1, ServiceSectionType::Sermon->value, startTime: 600.0, endTime: 1800.0, duration: 1200.0),
            ]);
            $this->fail('Merging must not silently overwrite an answer.');
        } catch (UnplacedOutputEdgeAnswerException $exception) {
            $this->assertEqualsCanonicalizing([$first->id, $second->id], $exception->unplacedSectionIds());
        }

        $this->assertSame(1195.4, OutputEdgeAnswers::recordsOf($first->fresh())['end']['time']);
        $this->assertSame(1795.4, OutputEdgeAnswers::recordsOf($second->fresh())['end']['time']);
        $this->assertSame(2, $log->serviceSections()->count());
    }

    #[Test]
    public function cleared_edge_history_does_not_block_removing_a_section(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->sectionWithOutputEdge($log, 1, 600.0, 1800.0, 1795.4);
        $answers = $section->metadata->raw[OutputEdgeAnswers::METADATA_KEY];
        $section->update(['metadata' => [OutputEdgeAnswers::METADATA_KEY => ['history' => [$answers['end']]]]]);

        $this->service->sync($log, []);

        $this->assertSame(0, $log->serviceSections()->count());
    }

    #[Test]
    public function cleared_edge_history_follows_an_overlapping_section(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->create();
        $section = $this->sectionWithOutputEdge($log, 1, 600.0, 1800.0, 1795.4);
        $history = [$section->metadata->raw[OutputEdgeAnswers::METADATA_KEY]['end']];
        $section->update(['metadata' => [OutputEdgeAnswers::METADATA_KEY => ['history' => $history]]]);

        $this->service->sync($log, [
            $this->sectionData(null, 1, ServiceSectionType::Sermon->value, startTime: 610.0, endTime: 1790.0, duration: 1180.0),
        ]);

        $section = $this->sectionAt($log, 1);
        $this->assertSame([], OutputEdgeAnswers::recordsOf($section));
        $this->assertEquals($history, $section->metadata->raw[OutputEdgeAnswers::METADATA_KEY]['history']);
    }

    private function sectionWithOutputEdge(MediaProcessingLog $processingLog, int $sectionOrder, float $startTime, float $endTime, float $end): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => $sectionOrder,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $endTime - $startTime,
            'metadata' => ['review_flags' => [], OutputEdgeAnswers::METADATA_KEY => ['end' => ['time' => $end, 'set_by_user_id' => 1,
                'set_at' => '2026-10-16T12:00:00+00:00', 'from_time' => $endTime, 'section_span' => ['start_time' => $startTime, 'end_time' => $endTime]]]],
        ]);
    }

    private function heldSection(
        MediaProcessingLog $processingLog,
        ServiceSectionType $type,
        int $sectionOrder,
        float $startTime,
        float $endTime,
    ): ServiceSection {
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $processingLog->id,
            'church_service_item_id' => null,
            'section_type' => $type->value,
            'section_order' => $sectionOrder,
            'title' => null,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $endTime - $startTime,
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);

        app(HoldSectionForContentReview::class)($section, self::HOLD_REASON, self::HOLD_EVIDENCE, ContentHoldCheck::Judgement);

        return $section->refresh();
    }

    private function sectionAt(MediaProcessingLog $processingLog, int $sectionOrder): ServiceSection
    {
        return ServiceSection::query()
            ->where('media_processing_log_id', $processingLog->id)
            ->where('section_order', $sectionOrder)
            ->firstOrFail();
    }

    private function assertHeld(ServiceSection $section): void
    {
        $metadata = $section->metadata?->toArray() ?? [];

        $this->assertTrue($section->needs_manual_review, "Section {$section->id} must stay in manual review.");
        $this->assertContains(HoldSectionForContentReview::FLAG, $metadata['review_flags'] ?? [], "Section {$section->id} must carry the hold flag.");
        $this->assertSame(self::HOLD_REASON, $metadata[HoldSectionForContentReview::METADATA_KEY][0]['reason'] ?? null);
        $this->assertSame(self::HOLD_EVIDENCE, $metadata[HoldSectionForContentReview::METADATA_KEY][0]['evidence'] ?? null);
    }

    private function assertNotHeld(ServiceSection $section): void
    {
        $this->assertFalse($section->needs_manual_review, "Section {$section->id} must not be held.");
        $this->assertNotContains(HoldSectionForContentReview::FLAG, $section->metadata?->toArray()['review_flags'] ?? []);
    }

    /**
     * @return array{
     *     church_service_item_id: int|null,
     *     section_type: string,
     *     section_order: int,
     *     title: ?string,
     *     start_time: float,
     *     end_time: float,
     *     duration: float,
     *     status: string,
     *     needs_manual_review: bool,
     *     source_segment_ids: array<int, int>,
     *     metadata: array<string, mixed>
     * }
     */
    private function sectionData(
        ?int $churchServiceItemId,
        int $sectionOrder,
        string $sectionType,
        ?string $title = null,
        float $startTime = 60.0,
        float $endTime = 180.0,
        float $duration = 120.0,
        bool $needsManualReview = false,
        string $classificationMode = 'openlp_aligned'
    ): array {
        return [
            'church_service_item_id' => $churchServiceItemId,
            'section_type' => $sectionType,
            'section_order' => $sectionOrder,
            'title' => $title,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $duration,
            'status' => ServiceSectionStatus::Identified->value,
            'needs_manual_review' => $needsManualReview,
            'source_segment_ids' => [1],
            'metadata' => [
                'confidence_level' => 'high',
                'classification_mode' => $classificationMode,
            ],
        ];
    }
}
