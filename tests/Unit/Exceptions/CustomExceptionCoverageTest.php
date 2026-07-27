<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Contracts\ProvidesSafeMessage;
use App\Enums\MediaType;
use App\Enums\SermonService;
use App\Exceptions\ApiBibleBudgetExhaustedException;
use App\Exceptions\InvalidFileException;
use App\Exceptions\NonRetryableTranscriptionException;
use App\Exceptions\ProcessingException;
use App\Exceptions\SafeInvalidArgumentException;
use App\Exceptions\SegmentationException;
use App\Exceptions\SermonRichnessDowngradeException;
use App\Exceptions\TranscriptionException;
use App\Exceptions\VideoProcessingException;
use App\Models\Sermon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomExceptionCoverageTest extends TestCase
{
    #[Test]
    public function safe_invalid_argument_exception_implements_provides_safe_message(): void
    {
        $exception = new SafeInvalidArgumentException('Invalid argument passed');

        $this->assertInstanceOf(ProvidesSafeMessage::class, $exception);
        $this->assertSame('Invalid argument passed', $exception->getSafeMessage());
    }

    #[Test]
    public function processing_exception_implements_provides_safe_message(): void
    {
        $exception = new ProcessingException('Media processing failed');

        $this->assertInstanceOf(ProvidesSafeMessage::class, $exception);
        $this->assertSame('Media processing failed', $exception->getSafeMessage());
    }

    #[Test]
    public function api_bible_budget_exhausted_exception_inherits_from_runtime_exception(): void
    {
        $exception = new ApiBibleBudgetExhaustedException('API limit reached');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    #[Test]
    public function invalid_file_exception_implements_provides_safe_message_correctly(): void
    {
        $exception = new InvalidFileException(['file too large', 'unsupported extension']);

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertInstanceOf(ProvidesSafeMessage::class, $exception);
        $this->assertSame('Invalid file: file too large, unsupported extension', $exception->getSafeMessage());
    }

    #[Test]
    public function non_retryable_transcription_exception_implements_provides_safe_message_correctly(): void
    {
        $exception = new NonRetryableTranscriptionException('Unrecoverable transcription error');

        $this->assertInstanceOf(TranscriptionException::class, $exception);
        $this->assertInstanceOf(ProvidesSafeMessage::class, $exception);
        $this->assertSame('Unrecoverable transcription error', $exception->getSafeMessage());
    }

    #[Test]
    public function segmentation_exception_implements_provides_safe_message_correctly(): void
    {
        $exception = new SegmentationException('Livestream segmentation failed');

        $this->assertInstanceOf(ProcessingException::class, $exception);
        $this->assertInstanceOf(ProvidesSafeMessage::class, $exception);
        $this->assertSame('Livestream segmentation failed', $exception->getSafeMessage());
    }

    #[Test]
    public function transcription_exception_implements_provides_safe_message_correctly(): void
    {
        $exception = new TranscriptionException('Transcription service timeout');

        $this->assertInstanceOf(ProcessingException::class, $exception);
        $this->assertInstanceOf(ProvidesSafeMessage::class, $exception);
        $this->assertSame('Transcription service timeout', $exception->getSafeMessage());
    }

    #[Test]
    public function video_processing_exception_implements_provides_safe_message_correctly(): void
    {
        $exception = new VideoProcessingException('FFmpeg processing error');

        $this->assertInstanceOf(ProcessingException::class, $exception);
        $this->assertInstanceOf(ProvidesSafeMessage::class, $exception);
        $this->assertSame('FFmpeg processing error', $exception->getSafeMessage());
    }

    #[Test]
    public function sermon_richness_downgrade_exception_implements_provides_safe_message_correctly(): void
    {
        $exception = new SermonRichnessDowngradeException('Refusing to downgrade');

        $this->assertInstanceOf(ProcessingException::class, $exception);
        $this->assertInstanceOf(ProvidesSafeMessage::class, $exception);
        $this->assertSame('Refusing to downgrade', $exception->getSafeMessage());
    }

    #[Test]
    public function sermon_richness_downgrade_exception_for_existing_builder_formats_message_correctly_for_audio(): void
    {
        // Testing Scribe guidance: Database-less unit test with unpersisted Eloquent models
        $existing = Sermon::factory()->make([
            'date' => '2024-07-20',
            'video_file_path' => null,
            'livestream_processing_id' => null,
        ]);

        $exception = SermonRichnessDowngradeException::forExisting(
            $existing,
            SermonService::Morning,
            MediaType::Audio
        );

        $this->assertStringContainsString('Existing sermon for 2024-07-20 morning is a audio; incoming is audio.', $exception->getMessage());
    }

    #[Test]
    public function sermon_richness_downgrade_exception_for_existing_builder_formats_message_correctly_for_video(): void
    {
        $existing = Sermon::factory()->make([
            'date' => '2024-07-20',
            'video_file_path' => 'sermons/video/test.mp4',
            'livestream_processing_id' => null,
        ]);

        $exception = SermonRichnessDowngradeException::forExisting(
            $existing,
            SermonService::Evening,
            MediaType::Audio
        );

        $this->assertStringContainsString('Existing sermon for 2024-07-20 evening is a video; incoming is audio.', $exception->getMessage());
    }

    #[Test]
    public function sermon_richness_downgrade_exception_for_existing_builder_formats_message_correctly_for_livestream(): void
    {
        $existing = Sermon::factory()->make([
            'date' => '2024-07-20',
            'video_file_path' => 'sermons/video/test.mp4',
            'livestream_processing_id' => 'ls-proc-123',
        ]);

        $exception = SermonRichnessDowngradeException::forExisting(
            $existing,
            SermonService::Morning,
            MediaType::Video
        );

        $this->assertStringContainsString('Existing sermon for 2024-07-20 morning is a livestream; incoming is video.', $exception->getMessage());
    }
}
