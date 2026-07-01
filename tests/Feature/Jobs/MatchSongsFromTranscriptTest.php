<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\TranscriptionServiceInterface;
use App\Enums\ServiceSectionSongMatchType;
use App\Enums\ServiceSectionType;
use App\Jobs\MatchSongsFromTranscript;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\OosAlignmentService;
use App\Services\Media\Audio\LocalWhisperTranscriptionService;
use App\Services\Media\Video\VideoExtractionService;
use App\Services\Processing\StorageAdapterHelper;
use App\Services\Song\SongLyricOcrService;
use App\Services\Song\SongLyricsMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MatchSongsFromTranscriptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        Config::set('media-processing.storage.sermon_disk', 'local');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.song_matching.enabled', true);
        Config::set('media-processing.song_matching.transcribe_song_openings', false);
        Config::set('media-processing.song_matching.ocr_enabled', false);
        Config::set('media-processing.song_matching.lyrics_threshold', 0.6);
    }

    // ---- Disabled / skipped paths ----

    #[Test]
    public function it_skips_when_song_matching_is_disabled(): void
    {
        Config::set('media-processing.song_matching.enabled', false);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        // The disabled feature flag must short-circuit the job with no side effects.
        $this->expectNotToPerformAssertions();

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

    }

    #[Test]
    public function it_skips_for_non_livestream_processing_type(): void
    {
        $log = MediaProcessingLog::factory()->audio()->pending()->create();

        // A non-livestream processing type must short-circuit with no side effects.
        $this->expectNotToPerformAssertions();

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );
    }

    #[Test]
    public function it_skips_when_there_are_no_unmatched_song_sections(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'metadata' => ['classification_mode' => 'audio_only'],
        ]);

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        // No unmatched sections exist, so the job leaves the confirmed section as-is.
        $this->assertDatabaseHas('service_sections', [
            'media_processing_log_id' => $log->id,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
        ]);
    }

    // ---- Title-hint matching via canonical key ----

    #[Test]
    public function it_matches_an_unmatched_song_section_via_title_hint_canonical_key(): void
    {
        $song = Song::factory()->create([
            'title' => 'Be Thou My Vision',
            'canonical_key' => 'be thou my vision',
            'lyrics_plain' => null,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'song_title_hint' => 'Be Thou My Vision',
                'review_flags' => ['unmatched_song_section'],
                'review_reason' => 'unmatched_song_section',
            ],
        ]);

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::Inferred, $section->song_match_type);
        $this->assertFalse($section->needs_manual_review);

        $match = $section->metadata['transcript_song_match'] ?? null;
        $this->assertIsArray($match);
        $this->assertSame($song->id, $match['song_id']);
        $this->assertSame('Be Thou My Vision', $match['title']);
        $this->assertSame(1.0, (float) $match['confidence']);
        $this->assertSame('title_hint_canonical', $match['match_source']);
    }

    #[Test]
    public function it_updates_linked_church_service_item_song_id_on_match(): void
    {
        $song = Song::factory()->create([
            'title' => 'In Christ Alone',
            'canonical_key' => 'in christ alone',
            'lyrics_plain' => null,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $item = ChurchServiceItem::factory()->create([
            'title' => 'Song',
            'song_id' => null,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'church_service_item_id' => $item->id,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'song_title_hint' => 'In Christ Alone',
                'review_flags' => ['unmatched_song_section'],
            ],
        ]);

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();
        $item->refresh();

        $this->assertSame(ServiceSectionSongMatchType::Inferred, $section->song_match_type);
        $this->assertSame($song->id, $item->song_id);
        $this->assertSame('In Christ Alone', $item->title);
    }

    // ---- Title-hint fuzzy fallback ----

    #[Test]
    public function it_falls_back_to_fuzzy_lyrics_matching_when_canonical_key_has_no_result(): void
    {
        $song = Song::factory()->create([
            'title' => 'How Great Thou Art',
            'canonical_key' => 'how great thou art',
            'lyrics_plain' => 'O Lord my God when I in awesome wonder consider all the worlds thy hands have made',
        ]);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                // Hint doesn't exactly match the canonical key.
                'song_title_hint' => 'O Lord my God when I in awesome wonder',
                'review_flags' => ['unmatched_song_section'],
            ],
        ]);

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();

        // Fuzzy match should have found the song via lyrics_plain.
        $this->assertSame(ServiceSectionSongMatchType::Inferred, $section->song_match_type);

        $match = $section->metadata['transcript_song_match'] ?? null;
        $this->assertIsArray($match);
        $this->assertSame($song->id, $match['song_id']);
    }

    // ---- Song opening transcription ----

    #[Test]
    public function it_transcribes_song_opening_when_title_hint_absent_and_transcription_enabled(): void
    {
        Config::set('media-processing.song_matching.transcribe_song_openings', true);
        Config::set('media-processing.song_matching.song_opening_transcription_seconds', 30);

        $song = Song::factory()->create([
            'title' => 'To God Be the Glory',
            'canonical_key' => 'to god be the glory',
            'lyrics_plain' => 'To God be the glory great things he hath done so loved he the world that he gave us his son',
        ]);

        $sourceFile = 'temp/service_source.mp4';
        Storage::disk('local')->put($sourceFile, 'fake-video-content');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $sourceFile,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'start_time' => 100.0,
            'end_time' => 280.0,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'review_flags' => ['unmatched_song_section'],
            ],
        ]);

        $openingTranscript = 'To God be the glory great things he hath done so loved he the world';

        $this->mock(VideoExtractionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractOptimizedAudio')
                ->once()
                ->andReturn(['audio_path' => 'temp/song_opening.mp3']);
        });

        $this->mock(TranscriptionServiceInterface::class, function (MockInterface $mock) use ($openingTranscript): void {
            $mock->shouldReceive('transcribe')
                ->once()
                ->andReturn($openingTranscript);
        });

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::Inferred, $section->song_match_type);
        $this->assertNotNull($section->metadata['song_opening_transcript'] ?? null);

        $match = $section->metadata['transcript_song_match'] ?? null;
        $this->assertIsArray($match);
        $this->assertSame($song->id, $match['song_id']);
        $this->assertSame('lyrics', $match['match_source']);
    }

    #[Test]
    public function it_uses_cpu_local_whisper_for_song_openings_only_in_local_environment(): void
    {
        Config::set('app.env', 'local');
        Config::set('media-processing.transcription.service', 'local');
        Config::set('media-processing.song_matching.transcribe_song_openings', true);
        Config::set('media-processing.song_matching.use_local_whisper_for_song_openings', true);
        Config::set('media-processing.song_matching.song_opening_local_whisper_url', 'http://whisper:8000');
        Config::set('media-processing.song_matching.song_opening_local_whisper_transcription_path', '/v1/audio/transcriptions');
        Config::set('media-processing.song_matching.song_opening_local_whisper_model', 'small');
        Config::set('media-processing.song_matching.song_opening_local_whisper_timeout', 1800);

        $song = Song::factory()->create([
            'title' => 'Great Is Thy Faithfulness',
            'canonical_key' => 'great is thy faithfulness',
            'lyrics_plain' => 'Great is thy faithfulness O God my Father morning by morning new mercies I see',
        ]);

        $sourceFile = 'temp/service_source.mp4';
        Storage::disk('local')->put($sourceFile, 'fake-video-content');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $sourceFile,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'start_time' => 100.0,
            'end_time' => 280.0,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'review_flags' => ['unmatched_song_section'],
            ],
        ]);

        $this->mock(VideoExtractionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractOptimizedAudio')
                ->once()
                ->andReturn(['audio_path' => 'temp/song_opening.mp3']);
        });

        $transcriptionService = \Mockery::mock(TranscriptionServiceInterface::class);
        $transcriptionService->shouldNotReceive('transcribe');

        $localWhisper = \Mockery::mock(LocalWhisperTranscriptionService::class);
        $localWhisper->shouldReceive('transcribeWithConfiguration')
            ->once()
            ->with(
                'temp/song_opening.mp3',
                $log->processing_id.'-song-'.$section->id.'-opening',
                'local',
                [
                    'url' => 'http://whisper:8000',
                    'transcription_path' => '/v1/audio/transcriptions',
                    'model' => 'small',
                    'timeout' => 1800,
                ],
            )
            ->andReturn('Great is thy faithfulness O God my Father morning by morning new mercies I see');

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            $transcriptionService,
            app(SongLyricOcrService::class),
            $localWhisper,
        );

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::Inferred, $section->song_match_type);
        $this->assertSame($song->id, $section->metadata['transcript_song_match']['song_id'] ?? null);
    }

    #[Test]
    public function it_uses_the_normal_transcription_service_for_song_openings_outside_local_environment(): void
    {
        Config::set('app.env', 'production');
        Config::set('media-processing.transcription.service', 'openai');
        Config::set('media-processing.song_matching.transcribe_song_openings', true);
        Config::set('media-processing.song_matching.use_local_whisper_for_song_openings', true);

        $song = Song::factory()->create([
            'title' => 'To God Be the Glory',
            'canonical_key' => 'to god be the glory',
            'lyrics_plain' => 'To God be the glory great things he hath done so loved he the world',
        ]);

        $sourceFile = 'temp/service_source.mp4';
        Storage::disk('local')->put($sourceFile, 'fake-video-content');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $sourceFile,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'start_time' => 100.0,
            'end_time' => 280.0,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'review_flags' => ['unmatched_song_section'],
            ],
        ]);

        $this->mock(VideoExtractionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractOptimizedAudio')
                ->once()
                ->andReturn(['audio_path' => 'temp/song_opening.mp3']);
        });

        $transcriptionService = \Mockery::mock(TranscriptionServiceInterface::class);
        $transcriptionService->shouldReceive('transcribe')
            ->once()
            ->with('temp/song_opening.mp3', $log->processing_id.'-song-'.$section->id.'-opening', 'local')
            ->andReturn('To God be the glory great things he hath done so loved he the world');

        $localWhisper = \Mockery::mock(LocalWhisperTranscriptionService::class);
        $localWhisper->shouldNotReceive('transcribeWithConfiguration');

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            $transcriptionService,
            app(SongLyricOcrService::class),
            $localWhisper,
        );

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::Inferred, $section->song_match_type);
        $this->assertSame($song->id, $section->metadata['transcript_song_match']['song_id'] ?? null);
    }

    // ---- Review bookkeeping is refreshed ----

    #[Test]
    public function it_re_runs_unmatched_song_review_applicator_after_matching(): void
    {
        $song = Song::factory()->create([
            'title' => 'And Can It Be',
            'canonical_key' => 'and can it be',
            'lyrics_plain' => null,
        ]);

        $churchService = ChurchService::factory()->create();
        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'church_service_id' => $churchService->id,
        ]);

        $unmatchedSection = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'song_title_hint' => 'And Can It Be',
                'review_flags' => ['unmatched_song_section'],
            ],
        ]);

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $unmatchedSection->refresh();

        // The section should now be inferred and the unmatched review flag cleared.
        $this->assertSame(ServiceSectionSongMatchType::Inferred, $unmatchedSection->song_match_type);
        $this->assertNotContains('unmatched_song_section', $unmatchedSection->metadata['review_flags'] ?? []);

        // The church service review state should have been refreshed.
        $churchService->refresh();
        $this->assertFalse($churchService->needs_review);
    }

    // ---- No match available ----

    #[Test]
    public function it_leaves_section_unmatched_when_no_catalog_song_matches_title_hint(): void
    {
        Song::factory()->create([
            'title' => 'Completely Different Song',
            'canonical_key' => 'completely different song',
            'lyrics_plain' => null,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'song_title_hint' => 'Unknown Hymn Not In Catalog',
                'review_flags' => ['unmatched_song_section'],
            ],
        ]);

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();

        // Section remains unmatched.
        $this->assertSame(ServiceSectionSongMatchType::Unmatched, $section->song_match_type);
        $this->assertNull($section->metadata['transcript_song_match'] ?? null);
    }

    // ---- OCR matching strategy ----

    #[Test]
    public function it_matches_via_ocr_when_title_hint_absent_and_ocr_enabled(): void
    {
        Config::set('media-processing.song_matching.ocr_enabled', true);

        $song = Song::factory()->create([
            'title' => 'Come People of the Risen King',
            'canonical_key' => 'come people of the risen king',
            'lyrics_plain' => 'Come people of the risen King who delight in songs of praising',
        ]);

        $sourceFile = 'temp/service_source.mp4';
        Storage::disk('local')->put($sourceFile, 'fake-video-content');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $sourceFile,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'start_time' => 200.0,
            'end_time' => 400.0,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'review_flags' => ['unmatched_song_section'],
            ],
        ]);

        $ocrText = 'Come people of the risen King who delight in songs of praising';

        $this->mock(SongLyricOcrService::class, function (MockInterface $mock) use ($ocrText): void {
            $mock->shouldReceive('extractLyricsSamples')
                ->once()
                ->andReturn([$ocrText]);
        });

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::Inferred, $section->song_match_type);
        $this->assertSame($ocrText, $section->metadata['song_ocr_text'] ?? null);

        $match = $section->metadata['transcript_song_match'] ?? null;
        $this->assertIsArray($match);
        $this->assertSame($song->id, $match['song_id']);
        $this->assertSame('ocr', $match['match_source']);
    }

    #[Test]
    public function it_falls_through_to_whisper_when_ocr_returns_null(): void
    {
        Config::set('media-processing.song_matching.ocr_enabled', true);
        Config::set('media-processing.song_matching.transcribe_song_openings', true);
        Config::set('media-processing.song_matching.song_opening_transcription_seconds', 30);

        $song = Song::factory()->create([
            'title' => 'Great Is Thy Faithfulness',
            'canonical_key' => 'great is thy faithfulness',
            'lyrics_plain' => 'Great is thy faithfulness O God my Father morning by morning new mercies I see',
        ]);

        $sourceFile = 'temp/service_source.mp4';
        Storage::disk('local')->put($sourceFile, 'fake-video-content');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $sourceFile,
        ]);

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'start_time' => 300.0,
            'end_time' => 500.0,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'review_flags' => ['unmatched_song_section'],
            ],
        ]);

        $openingTranscript = 'Great is thy faithfulness O God my Father morning by morning new mercies I see';

        // OCR returns null — no lyrics visible on screen.
        $this->mock(SongLyricOcrService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractLyricsSamples')->once()->andReturn([]);
        });

        $this->mock(VideoExtractionService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractOptimizedAudio')
                ->once()
                ->andReturn(['audio_path' => 'temp/song_opening.mp3']);
        });

        $this->mock(TranscriptionServiceInterface::class, function (MockInterface $mock) use ($openingTranscript): void {
            $mock->shouldReceive('transcribe')->once()->andReturn($openingTranscript);
        });

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::Inferred, $section->song_match_type);

        $match = $section->metadata['transcript_song_match'] ?? null;
        $this->assertIsArray($match);
        $this->assertSame($song->id, $match['song_id']);
        $this->assertSame('lyrics', $match['match_source']);
    }

    #[Test]
    public function it_skips_ocr_when_ocr_is_disabled(): void
    {
        Config::set('media-processing.song_matching.ocr_enabled', false);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Unmatched->value,
            'metadata' => ['classification_mode' => 'audio_only'],
        ]);

        $mockOcr = $this->mock(SongLyricOcrService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('extractLyricsSamples');
        });

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            $mockOcr,
        );

        // OCR was skipped (Mockery's shouldNotReceive guards the call), so the
        // section stays unmatched rather than gaining an OCR-derived match.
        $this->assertSame(
            ServiceSectionSongMatchType::Unmatched,
            $section->refresh()->song_match_type,
        );
    }

    // ---- Inferred sections still flagged as unmatched are rescued ----

    #[Test]
    public function it_processes_inferred_sections_that_still_carry_the_unmatched_review_flag(): void
    {
        Config::set('media-processing.song_matching.ocr_enabled', true);

        $song = Song::factory()->create([
            'title' => 'In Christ Alone',
            'canonical_key' => 'in christ alone',
            'lyrics_plain' => 'In Christ alone my hope is found He is my light my strength my song',
        ]);

        $sourceFile = 'temp/service_source.mp4';
        Storage::disk('local')->put($sourceFile, 'fake-video-content');

        $log = MediaProcessingLog::factory()->livestream()->pending()->create([
            'source_file_path' => $sourceFile,
        ]);

        $item = ChurchServiceItem::factory()->create([
            'title' => 'In Christ Alone',
            'song_id' => null,
        ]);

        // OoS alignment inferred a positional label but found no catalog-backed
        // evidence, so the unmatched review flag is still present.
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Inferred->value,
            'church_service_item_id' => $item->id,
            'start_time' => 200.0,
            'end_time' => 400.0,
            'needs_manual_review' => true,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'review_flags' => ['song_alignment_inferred', 'unmatched_song_section'],
                'review_reason' => 'song_alignment_inferred',
            ],
        ]);

        $ocrText = 'In Christ alone my hope is found He is my light my strength my song';

        $this->mock(SongLyricOcrService::class, function (MockInterface $mock) use ($ocrText): void {
            $mock->shouldReceive('extractLyricsSamples')->once()->andReturn([$ocrText]);
        });

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();
        $item->refresh();

        $match = $section->metadata['transcript_song_match'] ?? null;
        $this->assertIsArray($match);
        $this->assertSame($song->id, $match['song_id']);
        $this->assertSame('ocr', $match['match_source']);

        // The unmatched flag is cleared, but the positional-inference flag is kept
        // so a human still reviews the section.
        $this->assertNotContains('unmatched_song_section', $section->metadata['review_flags'] ?? []);
        $this->assertContains('song_alignment_inferred', $section->metadata['review_flags'] ?? []);
        $this->assertTrue($section->needs_manual_review);

        // The linked service item gains the catalog song link.
        $this->assertSame($song->id, $item->song_id);
    }

    #[Test]
    public function it_does_not_process_inferred_sections_without_the_unmatched_review_flag(): void
    {
        Song::factory()->create([
            'title' => 'Amazing Grace',
            'canonical_key' => 'amazing grace',
            'lyrics_plain' => null,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Inferred->value,
            'needs_manual_review' => false,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'song_title_hint' => 'Amazing Grace',
            ],
        ]);

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();

        // Already-resolved inferred sections are left untouched even though a
        // matching title hint exists.
        $this->assertNull($section->metadata['transcript_song_match'] ?? null);
    }

    // ---- Regression: confirmed sections are not processed ----

    #[Test]
    public function it_does_not_process_sections_that_are_already_confirmed(): void
    {
        $song = Song::factory()->create([
            'title' => 'Already Matched Song',
            'canonical_key' => 'already matched song',
            'lyrics_plain' => null,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => ServiceSectionSongMatchType::Confirmed->value,
            'needs_manual_review' => false,
            'metadata' => [
                'classification_mode' => 'audio_only',
                'song_title_hint' => 'Already Matched Song',
            ],
        ]);

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            app(OosAlignmentService::class),
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();

        // Should still be confirmed, not downgraded to inferred.
        $this->assertSame(ServiceSectionSongMatchType::Confirmed, $section->song_match_type);
        $this->assertNull($section->metadata['transcript_song_match'] ?? null);
    }

    // ---- LLM-first primary mode ----

    #[Test]
    public function it_confirms_an_llm_proposed_song_title_without_rerunning_oos_alignment_in_primary_mode(): void
    {
        config(['media-processing.service_structure.mode' => 'primary']);

        $song = Song::factory()->create([
            'title' => 'Praise My Soul the King of Heaven',
            'canonical_key' => 'praise my soul the king of heaven',
            'lyrics_plain' => null,
        ]);

        $log = MediaProcessingLog::factory()->livestream()->pending()->create();

        // The shape DetectServiceStructure persists: LLM anchoring already on
        // the section, the sung title carried as the first-choice hint.
        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song->value,
            'song_match_type' => null,
            'needs_manual_review' => false,
            'metadata' => [
                'classification_mode' => 'llm_structure',
                'song_title' => 'Praise My Soul the King of Heaven',
                'song_title_hint' => 'Praise My Soul the King of Heaven',
                'review_flags' => [],
            ],
        ]);

        // In primary mode the LLM owns OoS anchoring — the heuristic aligner
        // must not run and rewrite it.
        $alignmentService = $this->mock(OosAlignmentService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('alignForProcessingLog');
        });

        (new MatchSongsFromTranscript($log))->handle(
            app(SongLyricsMatchingService::class),
            $alignmentService,
            app(VideoExtractionService::class),
            app(StorageAdapterHelper::class),
            app(TranscriptionServiceInterface::class),
            app(SongLyricOcrService::class),
        );

        $section->refresh();

        $this->assertSame(ServiceSectionSongMatchType::Inferred, $section->song_match_type);
        $match = $section->metadata['transcript_song_match'] ?? null;
        $this->assertIsArray($match);
        $this->assertSame($song->id, $match['song_id']);
        $this->assertSame('title_hint_canonical', $match['match_source']);
    }
}
