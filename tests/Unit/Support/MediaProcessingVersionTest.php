<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\MediaProcessingVersion;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaProcessingVersionTest extends TestCase
{
    #[Test]
    public function libx264_cuts_keep_the_video_stamp_they_were_given_before_videotoolbox(): void
    {
        Config::set('media-processing.video_extraction.encoder', 'libx264');
        Config::set('media-processing.video_extraction.reencode_crf', 23);
        Config::set('media-processing.video_extraction.reencode_preset', 'veryfast');

        $this->assertSame(
            ['codec' => 'libx264', 'audio_codec' => 'aac', 'sample_rate' => 48000, 'audio_bitrate' => 128000, 'crf' => 23, 'preset' => 'veryfast'],
            MediaProcessingVersion::signature()['video'],
        );
    }

    #[Test]
    public function a_videotoolbox_cut_is_stamped_with_its_encoder_and_quality(): void
    {
        Config::set('media-processing.video_extraction.encoder', 'libx264');
        $libx264 = MediaProcessingVersion::signature();
        Config::set('media-processing.video_extraction.encoder', 'h264_videotoolbox');
        Config::set('media-processing.video_extraction.videotoolbox_quality', 60);

        $this->assertSame(
            ['codec' => 'h264_videotoolbox', 'audio_codec' => 'aac', 'sample_rate' => 48000, 'audio_bitrate' => 128000, 'quality' => 60, 'keyframe_interval' => 250],
            MediaProcessingVersion::signature()['video'],
        );
        $this->assertFalse(MediaProcessingVersion::matches($libx264));
    }
}
