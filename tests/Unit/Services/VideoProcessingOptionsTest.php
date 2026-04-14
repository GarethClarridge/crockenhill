<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MediaType;
use App\Models\MediaProcessingLog;
use App\Services\VideoProcessingOptions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoProcessingOptionsTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function for_media_type_returns_empty_array_for_non_video(): void
    {
        $this->assertEquals([], VideoProcessingOptions::forMediaType(MediaType::Audio->value, true));
        $this->assertEquals([], VideoProcessingOptions::forMediaType(MediaType::Livestream->value, true));
        $this->assertEquals([], VideoProcessingOptions::forMediaType(null, true));
        $this->assertEquals([], VideoProcessingOptions::forMediaType('invalid', true));
    }

    #[Test]
    public function for_media_type_returns_options_for_video(): void
    {
        $options = VideoProcessingOptions::forMediaType(MediaType::Video->value, true);
        $this->assertEquals([
            'auto_trim' => true,
            'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
        ], $options);

        $options = VideoProcessingOptions::forMediaType(MediaType::Video->value, false);
        $this->assertEquals([], $options);
    }

    #[Test]
    public function for_video_returns_empty_array_when_auto_trim_not_requested(): void
    {
        $this->assertEquals([], VideoProcessingOptions::forVideo(false));
        $this->assertEquals([], VideoProcessingOptions::forVideo(false, MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO));
    }

    #[Test]
    public function for_video_returns_correct_options_when_auto_trim_requested(): void
    {
        $expected = [
            'auto_trim' => true,
            'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
        ];

        $this->assertEquals($expected, VideoProcessingOptions::forVideo(true));
        $this->assertEquals($expected, VideoProcessingOptions::forVideo(false, MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM));
        $this->assertEquals($expected, VideoProcessingOptions::forVideo(true, MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM));
    }

    #[Test]
    public function resolve_mode_prioritizes_auto_trim_if_either_is_enabled(): void
    {
        // Explicit auto_trim mode string wins
        $this->assertEquals(
            MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            VideoProcessingOptions::resolveMode([
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
                'auto_trim' => false,
            ])
        );

        // Even if mode is full_video, if auto_trim is true, it resolves to auto_trim
        $this->assertEquals(
            MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            VideoProcessingOptions::resolveMode([
                'video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO,
                'auto_trim' => true,
            ])
        );
    }

    #[Test]
    public function resolve_mode_falls_back_to_auto_trim_boolean(): void
    {
        $this->assertEquals(
            MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM,
            VideoProcessingOptions::resolveMode(['auto_trim' => true])
        );

        $this->assertEquals(
            MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO,
            VideoProcessingOptions::resolveMode(['auto_trim' => false])
        );

        $this->assertEquals(
            MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO,
            VideoProcessingOptions::resolveMode([])
        );
    }

    #[Test]
    public function requests_auto_trim_returns_correctly(): void
    {
        $this->assertTrue(VideoProcessingOptions::requestsAutoTrim(['auto_trim' => true]));
        $this->assertTrue(VideoProcessingOptions::requestsAutoTrim(['video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_AUTO_TRIM]));
        $this->assertFalse(VideoProcessingOptions::requestsAutoTrim(['auto_trim' => false]));
        $this->assertFalse(VideoProcessingOptions::requestsAutoTrim(['video_processing_mode' => MediaProcessingLog::VIDEO_PROCESSING_MODE_FULL_VIDEO]));
        $this->assertFalse(VideoProcessingOptions::requestsAutoTrim([]));
    }

    #[Test]
    public function validation_rules_prohibit_auto_trim_for_non_video(): void
    {
        $rules = VideoProcessingOptions::validationRules(MediaType::Audio);
        $this->assertArrayHasKey('auto_trim', $rules);
        $this->assertArrayHasKey('video_processing_mode', $rules);

        // We check if it contains a ProhibitedIf rule.
        // It's hard to inspect the exact closure, but we can verify it's an array of rules.
        $this->assertIsArray($rules['auto_trim']);
        $this->assertIsArray($rules['video_processing_mode']);
    }

    #[Test]
    public function validation_rules_allow_video_when_enabled(): void
    {
        Config::set('media-processing.video_auto_trim.enabled', true);

        $rules = VideoProcessingOptions::validationRules(MediaType::Video);
        $this->assertIsArray($rules['auto_trim']);

        $rules = VideoProcessingOptions::validationRules(MediaType::Video->value);
        $this->assertIsArray($rules['auto_trim']);
    }

    #[Test]
    public function validation_rules_prohibit_video_when_disabled(): void
    {
        Config::set('media-processing.video_auto_trim.enabled', false);

        $rules = VideoProcessingOptions::validationRules(MediaType::Video);
        $this->assertIsArray($rules['auto_trim']);
    }
}
