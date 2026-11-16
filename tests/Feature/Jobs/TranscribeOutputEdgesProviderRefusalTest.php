<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Data\ChurchServiceTranscript;
use App\Enums\ServiceSectionType;
use App\Exceptions\NonRetryableTranscriptionException;
use App\Exceptions\OutputEdgeTimingsMissing;
use App\Jobs\TranscribeOutputEdges;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PrepareOutputEdgeEvidence;
use App\Services\ChurchService\SongEndSpeechOnset;
use App\Services\ChurchService\SongEndStems;
use App\Services\ChurchService\SongTailSpeechOnset;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Processing\StorageAdapterHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Audio\TranscriptionResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WP64 (option C): a provider that cannot decode a window as asked, such as the OpenAI API refusing the
 * aligned song-end windows, leaves that window unavailable and the step carries on (register R13, G2).
 */
class TranscribeOutputEdgesProviderRefusalTest extends TestCase
{
    use DatabaseTransactions;

    private string $audio = '';

    protected function tearDown(): void
    {
        if ($this->audio !== '' && is_file($this->audio)) {
            unlink($this->audio);
        }
        parent::tearDown();
    }

    private function fadedSongRun(): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local',
            'media-processing.service_structure.transcription_service' => 'openai',
            'media-processing.service_structure.transcription_model' => 'whisper-1',
            'media-processing.transcription.openai_api_key' => 'test-key']);
        $log = MediaProcessingLog::factory()->livestream()->processing()->create(['duration' => 5000]);
        $log->putServiceTranscriptPath('temp/edges.json');
        Storage::disk('local')->put('temp/edges.json', json_encode(ChurchServiceTranscript::fromCues([
            ['start' => 100.0, 'end' => 110.0, 'text' => 'Words.'],
        ], 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));
        $log->writeProcessingMetadata(fn (array $metadata): array => [...$metadata,
            'service_artifacts' => [['kind' => 'audio', 'disk' => 'local', 'path' => 'service-audio/test.mp3']]]);
        $lyrics = 'Glory to God in the highest';
        $song = Song::factory()->create(['lyrics_plain' => $lyrics]);
        ServiceSection::factory()->create(['media_processing_log_id' => $log->id, 'section_type' => ServiceSectionType::Song, 'start_time' => 90.0, 'end_time' => 200.0,
            'metadata' => ['transcript_song_match' => ['song_id' => $song->id]]]);
        $this->partialMock(CueSafeExtractionPlan::class, function (MockInterface $mock): void {
            $mock->shouldReceive('songEndSpeechEdges')->andReturn([]);
            $mock->shouldReceive('forSpans')->andReturn(['segments' => [['start_time' => 90.0, 'end_time' => 203.4]],
                'cue_edge_widening' => [['span_index' => 0, 'edge' => 'end', 'time' => 203.4, 'reason' => 'song_end_speech',
                    'original_time' => 200.0, 'replaced' => ['time' => 203.4, 'reason' => 'song_end_silence']]]]);
        });
        $this->audio = (string) tempnam(sys_get_temp_dir(), 'edge-refusal-');
        file_put_contents($this->audio, 'edge audio');
        $extractor = \Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->andReturn($this->audio);
        $extractor->shouldReceive('delete');
        $this->app->instance(ServiceAudioWindowExtractor::class, $extractor);
        $storage = \Mockery::mock(StorageAdapterHelper::class);
        $storage->shouldReceive('downloadToTemp')->andReturn('/audio.mp3');
        $storage->shouldReceive('isS3CompatibleDisk')->andReturn(false);
        $this->app->instance(StorageAdapterHelper::class, $storage);
        $this->mock(SongEndStems::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepare')->andReturn(['cuts' => 1, 'separated' => 0, 'compute_seconds' => 0.0]);
        });
        OpenAI::fake(array_fill(0, 12, TranscriptionResponse::fake(['text' => 'Amen', 'words' => [['start' => 1.0, 'end' => 1.5, 'word' => 'Amen']]])));

        return $log;
    }

    #[Test]
    public function a_provider_that_cannot_align_leaves_the_song_end_windows_unavailable_and_the_step_completes(): void
    {
        $log = $this->fadedSongRun();

        app()->call([new TranscribeOutputEdges($log), 'handle']);

        $evidence = app(OutputEdgeWordTimings::class);
        foreach (app(SongEndSpeechOnset::class)->windows(203.4, 5000.0) as $window) {
            try {
                $evidence->read($log->fresh(), $window);
                $this->fail('An aligned window the provider refused must not be banked.');
            } catch (OutputEdgeTimingsMissing) {
                $this->addToAssertionCount(1);
            }
        }
        $tail = array_values(app(SongTailSpeechOnset::class)->windows(200.0, 5000.0))[0];
        $this->assertNotEmpty($evidence->read($log->fresh(), $tail)['words'], 'The windows the provider can decode are still banked.');
    }

    #[Test]
    public function the_step_summary_names_each_unavailable_window_with_its_provider_and_reason(): void
    {
        $log = $this->fadedSongRun();
        $summary = app(PrepareOutputEdgeEvidence::class)->prepare($log);

        $this->assertSame(4, $summary['song_fade_cuts']['windows']);
        $this->assertCount(4, $summary['song_fade_cuts']['unavailable']);
        $this->assertSame('openai', $summary['song_fade_cuts']['unavailable'][0]['provider']);
        $this->assertStringContainsString('align', $summary['song_fade_cuts']['unavailable'][0]['reason']);
        $this->assertArrayNotHasKey('unavailable', $summary['song_tails']);
    }

    #[Test]
    public function another_non_retryable_failure_still_fails_the_step(): void
    {
        $log = $this->fadedSongRun();
        config(['media-processing.transcription.openai_api_key' => null]);

        $this->expectException(NonRetryableTranscriptionException::class);

        app()->call([new TranscribeOutputEdges($log), 'handle']);
    }
}
