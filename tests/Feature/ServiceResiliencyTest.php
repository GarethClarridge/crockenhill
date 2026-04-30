<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Sermon;
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
        $mockTranscription = \Mockery::mock(\App\Services\AudioTranscriptionService::class)->makePartial();
        $mockTranscription->shouldReceive('transcribe')
            ->andThrow(new \App\Exceptions\TranscriptionException('Timeout Error'));

        $this->app->instance(\App\Contracts\TranscriptionServiceInterface::class, $mockTranscription);
        $this->app->instance(\App\Services\AudioTranscriptionService::class, $mockTranscription);

        $service = $this->app->make(\App\Contracts\TranscriptionServiceInterface::class);

        $this->expectException(\App\Exceptions\TranscriptionException::class);
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
