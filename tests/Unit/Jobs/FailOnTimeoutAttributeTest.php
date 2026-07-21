<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\AnalyzeSegments;
use App\Jobs\EnhanceAudio;
use App\Jobs\GenerateRmsLog;
use App\Jobs\TranscribeAudio;
use Illuminate\Queue\Attributes\FailOnTimeout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Pins the `#[FailOnTimeout]` attribute on jobs whose timeouts indicate a
 * stuck FFmpeg / analysis process, and pins its deliberate absence on
 * external-API jobs (Whisper transcription) where timeouts are usually
 * transient and the retry is the correct response.
 */
class FailOnTimeoutAttributeTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function jobsThatFailOnTimeout(): array
    {
        return [
            'EnhanceAudio' => [EnhanceAudio::class],
            'GenerateRmsLog' => [GenerateRmsLog::class],
            'AnalyzeSegments' => [AnalyzeSegments::class],
        ];
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function jobsThatRetryOnTimeout(): array
    {
        return [
            'TranscribeAudio' => [TranscribeAudio::class],
        ];
    }

    /**
     * @param  class-string  $jobClass
     */
    #[Test]
    #[DataProvider('jobsThatFailOnTimeout')]
    public function job_is_marked_to_fail_on_timeout(string $jobClass): void
    {
        $attributes = (new ReflectionClass($jobClass))->getAttributes(FailOnTimeout::class);

        $this->assertNotEmpty(
            $attributes,
            "{$jobClass} must carry #[FailOnTimeout]; its timeouts indicate a stuck process, not a transient error."
        );
    }

    /**
     * @param  class-string  $jobClass
     */
    #[Test]
    #[DataProvider('jobsThatRetryOnTimeout')]
    public function transcription_jobs_intentionally_retry_on_timeout(string $jobClass): void
    {
        $attributes = (new ReflectionClass($jobClass))->getAttributes(FailOnTimeout::class);

        $this->assertEmpty(
            $attributes,
            "{$jobClass} must NOT carry #[FailOnTimeout]: OpenAI Whisper timeouts are usually transient and retries succeed."
        );
    }
}
