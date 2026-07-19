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
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomExceptionCoverageTest extends TestCase
{
    #[Test]
    public function safe_invalid_argument_exception_behaves_correctly(): void
    {
        $exception = new SafeInvalidArgumentException('Validation message here');

        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
        $this->assertInstanceOf(ProvidesSafeMessage::class, $exception);
        $this->assertSame('Validation message here', $exception->getMessage());
        $this->assertSame('Validation message here', $exception->getSafeMessage());
    }

    #[Test]
    public function processing_exception_behaves_correctly(): void
    {
        // Test default constructor
        $exception = new ProcessingException();
        $this->assertSame('A processing error occurred', $exception->getMessage());
        $this->assertSame('A processing error occurred', $exception->getSafeMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertNull($exception->getPrevious());

        // Test custom constructor
        $previous = new \Exception('Underlying');
        $customException = new ProcessingException('My message', 400, $previous);
        $this->assertSame('My message', $customException->getMessage());
        $this->assertSame('My message', $customException->getSafeMessage());
        $this->assertSame(400, $customException->getCode());
        $this->assertSame($previous, $customException->getPrevious());
    }

    #[Test]
    public function segmentation_exception_behaves_correctly(): void
    {
        $exception = new SegmentationException();
        $this->assertSame('Video segmentation failed', $exception->getMessage());
        $this->assertSame('Video segmentation failed', $exception->getSafeMessage());

        $customException = new SegmentationException('Custom segmentation error');
        $this->assertSame('Custom segmentation error', $customException->getMessage());
    }

    #[Test]
    public function invalid_file_exception_behaves_correctly(): void
    {
        $errors = ['File is too large', 'Invalid extension'];
        $exception = new InvalidFileException($errors);

        $this->assertInstanceOf(\Exception::class, $exception);
        $this->assertInstanceOf(ProvidesSafeMessage::class, $exception);
        $this->assertSame('Invalid file: File is too large, Invalid extension', $exception->getMessage());
        $this->assertSame('Invalid file: File is too large, Invalid extension', $exception->getSafeMessage());
    }

    #[Test]
    public function non_retryable_transcription_exception_behaves_correctly(): void
    {
        $exception = new NonRetryableTranscriptionException('API limit reached');

        $this->assertInstanceOf(TranscriptionException::class, $exception);
        $this->assertSame('API limit reached', $exception->getMessage());
    }

    #[Test]
    public function api_bible_budget_exhausted_exception_behaves_correctly(): void
    {
        $exception = new ApiBibleBudgetExhaustedException('Daily budget exceeded');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame('Daily budget exceeded', $exception->getMessage());
    }

    #[Test]
    public function video_processing_exception_behaves_correctly(): void
    {
        $exception = new VideoProcessingException();
        $this->assertSame('Video processing failed', $exception->getMessage());

        $customException = new VideoProcessingException('Conversion failed');
        $this->assertSame('Conversion failed', $customException->getMessage());
    }

    #[Test]
    public function transcription_exception_behaves_correctly(): void
    {
        $exception = new TranscriptionException();
        $this->assertSame('Audio transcription failed', $exception->getMessage());

        $customException = new TranscriptionException('Whisper API offline');
        $this->assertSame('Whisper API offline', $customException->getMessage());
    }

    #[Test]
    public function sermon_richness_downgrade_exception_expresses_livestream_correctly(): void
    {
        $sermon = new Sermon();
        $sermon->date = Carbon::parse('2026-07-20');
        $sermon->livestream_processing_id = 'ls-12345';

        $exception = SermonRichnessDowngradeException::forExisting(
            $sermon,
            SermonService::Morning,
            MediaType::Audio
        );

        $this->assertSame(
            'Refusing to overwrite richer sermon. Existing sermon for 2026-07-20 morning is a livestream; incoming is audio.',
            $exception->getMessage()
        );
    }

    #[Test]
    public function sermon_richness_downgrade_exception_expresses_video_correctly(): void
    {
        $sermon = new Sermon();
        $sermon->date = Carbon::parse('2026-07-20');
        $sermon->video_file_path = 'sermons/video.mp4';

        $exception = SermonRichnessDowngradeException::forExisting(
            $sermon,
            SermonService::Evening,
            MediaType::Audio
        );

        $this->assertSame(
            'Refusing to overwrite richer sermon. Existing sermon for 2026-07-20 evening is a video; incoming is audio.',
            $exception->getMessage()
        );
    }

    #[Test]
    public function sermon_richness_downgrade_exception_expresses_audio_correctly(): void
    {
        $sermon = new Sermon();
        $sermon->date = Carbon::parse('2026-07-20');

        $exception = SermonRichnessDowngradeException::forExisting(
            $sermon,
            SermonService::Other,
            MediaType::Audio
        );

        $this->assertSame(
            'Refusing to overwrite richer sermon. Existing sermon for 2026-07-20 other is a audio; incoming is audio.',
            $exception->getMessage()
        );
    }
}
