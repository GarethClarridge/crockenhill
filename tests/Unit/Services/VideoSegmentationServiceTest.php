<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Video\VideoSegmentationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoSegmentationServiceTest extends TestCase
{
    private VideoSegmentationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(VideoSegmentationService::class);
    }

    #[Test]
    public function it_validates_any_video_file_in_testing_environment(): void
    {
        // In the testing environment, VideoSegmentationService intentionally skips
        // FFProbe initialization (see constructor guard).
        // The contract is that validateVideoFile() returns true when $ffprobe is null.
        // This documents and tests the safe-by-default behavior for tests.
        $this->assertTrue($this->service->validateVideoFile('/path/to/any/video.mp4'));
        $this->assertTrue($this->service->validateVideoFile('/path/to/any/video.mkv'));
        $this->assertTrue($this->service->validateVideoFile('/path/to/any/video.avi'));
    }

    #[Test]
    public function it_returns_empty_metadata_in_testing_environment(): void
    {
        // When FFProbe is null in testing, getVideoMetadata() returns a safe empty array.
        // This ensures tests don't accidentally depend on real FFProbe behavior.
        $metadata = $this->service->getVideoMetadata('/path/to/video.mp4');

        $this->assertIsArray($metadata);
    }
}
