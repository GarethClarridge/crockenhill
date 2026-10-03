<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Data\ChurchServiceTranscript;
use App\Models\MediaProcessingLog;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\ChurchService\OutputEdgeWordTimings;
use App\Services\ChurchService\PrepareOutputEdgeWordTimings;
use App\Services\Media\Audio\LocalWhisperServiceTranscriptionService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\MediaProcessingVersion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WordTimedOutputEdgesTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    #[DataProvider('edgeCases')]
    public function output_edges_use_the_largest_adjacent_pause(string $edge, float $original, array $cue, array $words, float $expected): void
    {
        $log = $this->log($cue);
        $this->bank($log, $cue, $words);
        $span = ['start_time' => $edge === 'start' ? $original : 10.0, 'end_time' => $edge === 'end' ? $original : 5000.0];
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [$span]);
        $this->assertEqualsWithDelta($expected, $plan['segments'][0][$edge.'_time'], 0.001);
        $this->assertSame($original, $plan['cue_edge_widening'][0]['original_time']);
    }

    public static function edgeCases(): array
    {
        return [
            '936 prayer name then amen then sermon' => ['start', 2594.18, ['start' => 2593.98, 'end' => 2594.38, 'text' => 'name.'], [
                ['start' => 2593.0, 'end' => 2593.98, 'word' => 'Jesus'],
                ['start' => 2593.98, 'end' => 2594.38, 'word' => 'name.'],
                ['start' => 2594.4, 'end' => 2594.55, 'word' => 'Amen.'],
                ['start' => 2595.3, 'end' => 2595.38, 'word' => 'Well'],
            ], 2594.55],
            '1346 prayer and talk share cue' => ['start', 366.001, ['start' => 362.08, 'end' => 366.41, 'text' => 'things in Jesus name and for his sake. Amen. Well, I'], [
                ['start' => 361.1, 'end' => 362.08, 'word' => 'these'],
                ['start' => 362.08, 'end' => 364.8, 'word' => 'prayer'],
                ['start' => 364.9, 'end' => 365.1, 'word' => 'Amen.'],
                ['start' => 366.0, 'end' => 366.2, 'word' => 'Well,'],
                ['start' => 366.25, 'end' => 366.5, 'word' => 'I'],
                ['start' => 366.55, 'end' => 367.0, 'word' => 'wonder'],
            ], 366.0],
            '949 exclude prayer include thank you Mark' => ['start', 2744.99, ['start' => 2738.96, 'end' => 2744.99, 'text' => 'him in jesus name we ask amen thank you mark it is such a'], [
                ['start' => 2738.0, 'end' => 2738.9, 'word' => 'through'],
                ['start' => 2739.0, 'end' => 2740.1, 'word' => 'prayer'],
                ['start' => 2740.2, 'end' => 2740.4, 'word' => 'amen'],
                ['start' => 2741.5, 'end' => 2741.7, 'word' => 'thank'],
                ['start' => 2741.75, 'end' => 2744.99, 'word' => 'you Mark it is such a'],
                ['start' => 2745.0, 'end' => 2745.5, 'word' => 'privilege'],
            ], 2741.5],
            '1342 music cue must not swallow song' => ['end', 1581.8, ['start' => 1581.7, 'end' => 1621.0, 'text' => 'sung words'], [
                ['start' => 1580.8, 'end' => 1581.5, 'word' => 'Amen'],
                ['start' => 1584.0, 'end' => 1621.5, 'word' => 'singing'],
            ], 1581.8],
            '1148 thank you stretched over music tail' => ['end', 100.3, ['start' => 100.0, 'end' => 130.0, 'text' => 'Thank you.'], [
                ['start' => 100.0, 'end' => 100.2, 'word' => 'Thank'],
                ['start' => 100.2, 'end' => 100.5, 'word' => 'you.'],
            ], 100.5],
            '1267 sorry stretched over music tail' => ['start', 129.9, ['start' => 100.0, 'end' => 130.0, 'text' => "I'm sorry."], [
                ['start' => 100.0, 'end' => 100.2, 'word' => "I'm"],
                ['start' => 100.2, 'end' => 100.5, 'word' => 'sorry.'],
            ], 129.9],
        ];
    }

    #[Test]
    public function absent_cache_blocks_instead_of_using_whole_cues(): void
    {
        $log = $this->log(['start' => 99.0, 'end' => 101.0, 'text' => 'Amen']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('edge_word_timings_missing');
        app(CueSafeExtractionPlan::class)->forSpans($log, [['start_time' => 100.0, 'end_time' => 200.0]]);
    }

    #[Test]
    public function no_words_preserves_whole_cue_and_records_the_reason(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, []);
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 200.0]]);
        $this->assertSame(99.0, $plan['segments'][0]['start_time']);
        $this->assertSame('no_words_whole_cue', $plan['cue_edge_widening'][0]['reason']);
    }

    #[Test]
    public function cache_identity_binds_the_model_and_media_processing_version(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, []);
        config(['media-processing.transcription.local_whisper_model' => 'different']);
        $this->expectExceptionMessage('edge_word_timings_missing');
        app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 200.0]]);
    }

    #[Test]
    public function changed_processing_version_cannot_reuse_edge_evidence(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, []);
        config(['media-processing.media_processing_version' => 999]);
        $this->expectExceptionMessage('edge_word_timings_missing');
        app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 200.0]]);
    }

    #[Test]
    public function sub_millisecond_cue_noise_does_not_widen_a_no_words_edge(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.000000000001, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, []);
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 10.0, 'end_time' => 101.0]]);
        $this->assertSame(101.0, $plan['segments'][0]['end_time']);
    }

    #[Test]
    public function disagreeing_words_still_supply_the_pause_and_are_audited(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $this->bank($log, $cue, [['start' => 99.0, 'end' => 99.5, 'word' => 'Thanks']]);
        $plan = app(CueSafeExtractionPlan::class)->forSpans($log->fresh(), [['start_time' => 100.0, 'end_time' => 200.0]]);
        $this->assertSame(100.0, $plan['segments'][0]['start_time']);
        $this->assertTrue($plan['cue_edge_widening'][0]['text_disagreement']);
        $this->assertSame('Thanks', $plan['cue_edge_widening'][0]['word_before']['word']);
    }

    #[Test]
    public function preparation_maps_window_words_back_to_service_time_and_reuses_its_cache(): void
    {
        $cue = ['start' => 99.0, 'end' => 101.0, 'text' => 'Amen'];
        $log = $this->log($cue);
        $log->writeProcessingMetadata(fn (array $metadata): array => [...$metadata,
            'service_artifacts' => [['kind' => 'audio', 'disk' => 'local', 'path' => 'service-audio/test.mp3']]]);
        $extractor = \Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->with('/audio.mp3', 98.0, 102.0, $log->processing_id)->andReturn('/window.mp3');
        $extractor->shouldReceive('delete')->once()->with('/window.mp3');
        $storage = \Mockery::mock(StorageAdapterHelper::class);
        $storage->shouldReceive('downloadToTemp')->once()->andReturn('/audio.mp3');
        $storage->shouldReceive('isS3CompatibleDisk')->once()->andReturn(false);
        $whisper = \Mockery::mock(LocalWhisperServiceTranscriptionService::class);
        $whisper->shouldReceive('transcribeEdgeWindow')->once()->with('/window.mp3')->andReturn([['start' => 1.0, 'end' => 1.5, 'word' => 'Amen']]);
        $service = new PrepareOutputEdgeWordTimings(app(OutputEdgeWordTimings::class), $whisper, $extractor, app(ServiceArtifactStorage::class), $storage);
        $spans = [['start_time' => 100.0, 'end_time' => 200.0]];
        $this->assertSame(1, $service->prepare($log->fresh(), $spans)['decoded']);
        $this->assertSame(0, $service->prepare($log->fresh(), $spans)['decoded']);
        $payload = app(OutputEdgeWordTimings::class)->read($log->fresh(), ['start' => 98.0, 'end' => 102.0]);
        $this->assertEquals([['start' => 99.0, 'end' => 99.5, 'word' => 'Amen']], $payload['words']);
        $this->assertSame('temp/edge-test.json', $log->fresh()->serviceTranscriptPath());
    }

    private function log(array $cue): MediaProcessingLog
    {
        Storage::fake('local');
        config(['media-processing.storage.service_artifact_disk' => 'local']);
        $log = MediaProcessingLog::factory()->livestream()->create(['duration' => 5000]);
        $log->putServiceTranscriptPath('temp/edge-test.json');
        Storage::disk('local')->put('temp/edge-test.json', json_encode(ChurchServiceTranscript::fromCues([$cue], 5000, ChurchServiceTranscript::SOURCE_MOCK)->toArray(), JSON_THROW_ON_ERROR));

        return $log;
    }

    private function bank(MediaProcessingLog $log, array $cue, array $words): void
    {
        $identity = ['processing_id' => $log->processing_id, 'start' => max(0.0, $cue['start'] - 1), 'end' => $cue['end'] + 1,
            'model' => (string) config('media-processing.transcription.local_whisper_model', 'small'), 'media_processing' => MediaProcessingVersion::signature()];
        $key = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        app(ServiceArtifactStorage::class)->putJson($log->processing_id, 'edge-words-'.$key, ['identity' => $identity, 'words' => $words, 'compute_seconds' => 1.0]);
    }
}
