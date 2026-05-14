<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Enums\MediaType;
use App\Models\LivestreamSegment;
use App\Models\MediaProcessingLog;
use App\Services\LivestreamCreateSermonService;
use App\Services\MediaProcessingRunTransitionService;
use App\Services\ProcessingRunOrchestrator;
use App\Services\VideoStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LivestreamCreateSermonServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): LivestreamCreateSermonService
    {
        return app(LivestreamCreateSermonService::class);
    }

    private function mockStorageAllowsFile(string $path): void
    {
        $storage = $this->mock(VideoStorageService::class);
        $storage->shouldReceive('sourceVideoExistsForPath')->andReturn(true);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_confirmation_data_for_the_longest_speech_segment(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'source_file_path' => 'livestreams/service.mp4',
        ]);

        // Two speech segments; the longer one should be chosen
        LivestreamSegment::factory()->forProcessingLog($log->id)->speech()->create([
            'start_time' => 0,
            'end_time' => 600,
            'duration' => 600,
        ]);
        $longestSegment = LivestreamSegment::factory()->forProcessingLog($log->id)->speech()->create([
            'start_time' => 700,
            'end_time' => 4300,
            'duration' => 3600,
        ]);

        $transitions = $this->mock(MediaProcessingRunTransitionService::class);
        $transitions->shouldReceive('confirmSermonSegment')
            ->once()
            ->with(\Mockery::type(MediaProcessingLog::class), $longestSegment->id, null);

        $orchestrator = $this->mock(ProcessingRunOrchestrator::class);
        $orchestrator->shouldReceive('resumeAfterManualReview')->once();

        $storage = $this->mock(VideoStorageService::class);
        $storage->shouldReceive('sourceVideoExistsForPath')->with('livestreams/service.mp4')->andReturn(true);

        $this->app->forgetInstance(LivestreamCreateSermonService::class);

        $result = $this->makeService()->resumeUsingLongestSpeechSegment($log->processing_id);

        $this->assertSame($log->processing_id, $result['processing_id']);
        $this->assertSame($longestSegment->id, $result['segment_id']);
        $this->assertSame(700.0, $result['start_time']);
        $this->assertSame(4300.0, $result['end_time']);
        $this->assertSame(3600.0, $result['duration']);
    }

    // ── Validation failures ───────────────────────────────────────────────────

    #[Test]
    public function it_throws_when_processing_id_does_not_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Processing log not found');

        $this->makeService()->resumeUsingLongestSpeechSegment('does-not-exist');
    }

    #[Test]
    public function it_throws_when_the_processing_type_is_not_livestream(): void
    {
        $log = MediaProcessingLog::factory()->audio()->manualReviewRequired()->create([
            'processing_type' => MediaType::Audio,
            'source_file_path' => 'audio/service.mp3',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only livestream runs');

        $this->makeService()->resumeUsingLongestSpeechSegment($log->processing_id);
    }

    #[Test]
    public function it_throws_when_the_run_is_not_awaiting_manual_review(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->processing()->create([
            'source_file_path' => 'livestreams/service.mp4',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('awaiting manual sermon review');

        $this->makeService()->resumeUsingLongestSpeechSegment($log->processing_id);
    }

    #[Test]
    public function it_throws_when_the_source_video_file_is_missing(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'source_file_path' => 'livestreams/gone.mp4',
        ]);

        $storage = $this->mock(VideoStorageService::class);
        $storage->shouldReceive('sourceVideoExistsForPath')->andReturn(false);

        $this->app->forgetInstance(LivestreamCreateSermonService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('source video file is no longer available');

        $this->makeService()->resumeUsingLongestSpeechSegment($log->processing_id);
    }

    #[Test]
    public function it_throws_when_the_source_file_path_is_not_set(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'source_file_path' => null,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No source video path recorded');

        $this->makeService()->resumeUsingLongestSpeechSegment($log->processing_id);
    }

    #[Test]
    public function it_throws_when_no_speech_segment_exists(): void
    {
        $log = MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'source_file_path' => 'livestreams/service.mp4',
        ]);

        // Create only a non-speech segment
        LivestreamSegment::factory()->forProcessingLog($log->id)->song()->create();

        $storage = $this->mock(VideoStorageService::class);
        $storage->shouldReceive('sourceVideoExistsForPath')->andReturn(true);

        $this->app->forgetInstance(LivestreamCreateSermonService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No sermon segment found');

        $this->makeService()->resumeUsingLongestSpeechSegment($log->processing_id);
    }
}
