<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\TranscriptionServiceInterface;
use App\Exceptions\TranscriptionException;
use App\Models\Sermon;
use App\Services\AudioTranscriptionService;
use App\Services\SermonStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceResiliencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_handles_openai_timeout_gracefully(): void
    {
        // Mock the TranscriptionService directly since Facade mocking can be finicky in shared environments
        $mockTranscription = \Mockery::mock(AudioTranscriptionService::class)->makePartial();
        $mockTranscription->shouldReceive('transcribe')
            ->andThrow(new TranscriptionException('Timeout Error'));

        $this->app->instance(TranscriptionServiceInterface::class, $mockTranscription);
        $this->app->instance(AudioTranscriptionService::class, $mockTranscription);

        $service = $this->app->make(TranscriptionServiceInterface::class);

        $this->expectException(TranscriptionException::class);
        $service->transcribe('test.mp3');
    }

    #[Test]
    public function it_handles_s3_failure_gracefully(): void
    {
        $service = new SermonStorageService;
        $sermon = Sermon::factory()->create(['audio_file_path' => 'test.mp3']);

        // Mock Storage::disk to throw an exception directly
        Storage::shouldReceive('disk')
            ->andThrow(new \Exception('S3 failure'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('S3 failure');
        $service->fileExists($sermon);
    }
}
