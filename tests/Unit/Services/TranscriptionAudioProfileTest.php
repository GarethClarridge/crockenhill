<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Media\Audio\TranscriptionAudioProfile;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TranscriptionAudioProfileTest extends TestCase
{
    #[Test]
    public function it_reads_both_transcription_audio_profiles_from_one_owner(): void
    {
        Config::set('media-processing.audio_extraction.transcription_optimized', [
            'bitrate' => 56,
            'channels' => 1,
            'max_file_size' => 123,
        ]);
        Config::set('media-processing.audio_extraction.fallback_compression', [
            'bitrate' => 24,
            'channels' => 1,
        ]);

        $this->assertSame(['bitrate' => 56, 'channels' => 1, 'max_file_size' => 123], TranscriptionAudioProfile::optimized());
        $this->assertSame(['bitrate' => 24, 'channels' => 1], TranscriptionAudioProfile::fallback());
    }
}
