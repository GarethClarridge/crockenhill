<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Exceptions\InvalidFileException;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\SermonValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonValidationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private SermonValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(SermonValidationService::class);
    }

    // ---- validateAudioFile ----

    #[Test]
    public function it_passes_valid_audio_file(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1000, 'audio/mpeg');

        $this->service->validateAudioFile($file);

        $this->assertTrue(true); // Should not throw
    }

    #[Test]
    public function it_throws_exception_for_invalid_audio_file(): void
    {
        $file = UploadedFile::fake()->create('sermon.txt', 10, 'text/plain');

        $this->expectException(InvalidFileException::class);
        $this->expectExceptionMessage('Invalid file type');

        $this->service->validateAudioFile($file);
    }

    // ---- validateProcessingMetadata ----

    #[Test]
    public function it_returns_empty_errors_for_valid_metadata(): void
    {
        $errors = $this->service->validateProcessingMetadata([
            'source_type' => 'audio_upload',
            'original_filename' => 'sermon-2026-01-05.mp3',
        ]);

        $this->assertEmpty($errors);
    }

    #[Test]
    public function it_returns_error_for_missing_source_type(): void
    {
        $errors = $this->service->validateProcessingMetadata([
            'original_filename' => 'test.mp3',
        ]);

        $this->assertContains('Source type is required', $errors);
    }

    #[Test]
    public function it_returns_error_for_missing_filename(): void
    {
        $errors = $this->service->validateProcessingMetadata([
            'source_type' => 'audio_upload',
        ]);

        $this->assertContains('Original filename is required', $errors);
    }

    #[Test]
    public function it_returns_error_for_invalid_source_type(): void
    {
        $errors = $this->service->validateProcessingMetadata([
            'source_type' => 'invalid_type',
            'original_filename' => 'test.mp3',
        ]);

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'Invalid source type'))
        );
    }

    #[Test]
    public function it_detects_path_traversal_in_filename(): void
    {
        $errors = $this->service->validateProcessingMetadata([
            'source_type' => 'audio_upload',
            'original_filename' => '../../../etc/passwd',
        ]);

        $this->assertContains('Filename contains invalid characters', $errors);
    }

    #[Test]
    public function it_detects_backslash_in_filename(): void
    {
        $errors = $this->service->validateProcessingMetadata([
            'source_type' => 'audio_upload',
            'original_filename' => 'path\\to\\file.mp3',
        ]);

        $this->assertContains('Filename contains invalid characters', $errors);
    }

    #[Test]
    public function it_rejects_overly_long_filenames(): void
    {
        $errors = $this->service->validateProcessingMetadata([
            'source_type' => 'audio_upload',
            'original_filename' => str_repeat('a', 256).'.mp3',
        ]);

        $this->assertContains('Filename too long (maximum 255 characters)', $errors);
    }

    // ---- validateSermonData ----

    #[Test]
    public function it_validates_valid_sermon_data(): void
    {
        $errors = $this->service->validateSermonData([
            'title' => 'Test Sermon',
            'date' => '2026-01-05',
            'service' => 'morning',
        ]);

        $this->assertEmpty($errors);
    }

    #[Test]
    public function it_requires_sermon_title(): void
    {
        $errors = $this->service->validateSermonData([
            'date' => '2026-01-05',
        ]);

        $this->assertContains('Sermon title is required', $errors);
    }

    #[Test]
    public function it_requires_sermon_date(): void
    {
        $errors = $this->service->validateSermonData([
            'title' => 'Test',
        ]);

        $this->assertContains('Sermon date is required', $errors);
    }

    #[Test]
    public function it_rejects_invalid_service_type(): void
    {
        $errors = $this->service->validateSermonData([
            'title' => 'Test',
            'date' => '2026-01-05',
            'service' => 'midnight',
        ]);

        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'Invalid service type'))
        );
    }

    #[Test]
    public function it_rejects_invalid_slug_format(): void
    {
        $errors = $this->service->validateSermonData([
            'title' => 'Test',
            'date' => '2026-01-05',
            'slug' => 'Has Spaces And CAPS!',
        ]);

        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'lowercase letters'))
        );
    }

    #[Test]
    public function it_detects_duplicate_slug(): void
    {
        Sermon::factory()->create(['slug' => 'existing-slug']);

        $errors = $this->service->validateSermonData([
            'title' => 'Test',
            'date' => '2026-01-05',
            'slug' => 'existing-slug',
        ]);

        $this->assertContains('Slug already exists - must be unique', $errors);
    }

    #[Test]
    public function it_excludes_current_sermon_from_slug_check(): void
    {
        $sermon = Sermon::factory()->create(['slug' => 'my-slug']);

        $errors = $this->service->validateSermonData([
            'title' => 'Test',
            'date' => '2026-01-05',
            'slug' => 'my-slug',
            'sermon_id' => $sermon->id,
        ]);

        $this->assertNotContains('Slug already exists - must be unique', $errors);
    }

    // ---- generateFallbackTitle ----

    #[Test]
    public function it_generates_title_from_filename(): void
    {
        $sermon = Sermon::factory()->create(['date' => now()]);
        $log = MediaProcessingLog::factory()->create([
            'original_filename' => '2026-01-05_morning-service.mp3',
        ]);

        $title = $this->service->generateFallbackTitle($sermon, $log);

        $this->assertStringContainsString('Morning', $title);
        $this->assertStringContainsString('Service', $title);
        $this->assertStringNotContainsString('2026', $title);
    }

    #[Test]
    public function it_falls_back_to_date_based_title_when_filename_unusable(): void
    {
        $sermon = Sermon::factory()->create([
            'date' => now(),
            'service' => 'morning',
        ]);
        $log = MediaProcessingLog::factory()->create();

        // Simulate empty filename to test fallback path
        $log->original_filename = '';

        $title = $this->service->generateFallbackTitle($sermon, $log);

        $this->assertStringStartsWith('Sermon - ', $title);
        $this->assertStringContainsString('morning', $title);
    }

    // ---- generateFallbackData ----

    #[Test]
    public function it_generates_fallback_data_structure(): void
    {
        $sermon = Sermon::factory()->create([
            'title' => 'Untitled Sermon',
            'date' => now(),
        ]);
        $log = MediaProcessingLog::factory()->create([
            'original_filename' => '2026-02-10_evening-worship.mp3',
        ]);

        $data = $this->service->generateFallbackData($sermon, $log);

        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('slug', $data);
        $this->assertArrayHasKey('series', $data);
        $this->assertArrayHasKey('reference', $data);
        $this->assertArrayHasKey('points', $data);
        $this->assertNull($data['series']);
        $this->assertNull($data['reference']);
        $this->assertEquals(['Main Message'], $data['points']);
    }

    // ---- canRetryProcessing ----

    #[Test]
    public function it_allows_retry_for_normal_failures(): void
    {
        $log = MediaProcessingLog::factory()->failed()->create([
            'current_step' => 'audio_transcription',
            'error_message' => 'Temporary API error',
        ]);

        $this->assertTrue($this->service->canRetryProcessing($log));
    }

    #[Test]
    public function it_blocks_retry_for_manual_review(): void
    {
        $log = MediaProcessingLog::factory()->failed()->create([
            'current_step' => 'manual_review_required',
        ]);

        $this->assertFalse($this->service->canRetryProcessing($log));
    }

    #[Test]
    public function it_blocks_retry_for_old_processing(): void
    {
        $log = MediaProcessingLog::factory()->failed()->create([
            'created_at' => now()->subDays(8),
        ]);

        $this->assertFalse($this->service->canRetryProcessing($log));
    }

    #[Test]
    public function it_blocks_retry_for_critical_failures(): void
    {
        $log = MediaProcessingLog::factory()->failed()->create([
            'error_message' => 'file_not_found: The audio file was missing',
        ]);

        $this->assertFalse($this->service->canRetryProcessing($log));
    }

    // ---- requiresManualReview ----

    #[Test]
    public function it_requires_review_for_critical_steps(): void
    {
        $log = MediaProcessingLog::factory()->failed()->create([
            'current_step' => 'creating_sermon_record',
        ]);

        $this->assertTrue($this->service->requiresManualReview($log));
    }

    #[Test]
    public function it_requires_review_for_error_patterns(): void
    {
        $log = MediaProcessingLog::factory()->failed()->create([
            'error_message' => 'Storage failure: disk full',
        ]);

        $this->assertTrue($this->service->requiresManualReview($log));
    }

    #[Test]
    public function it_does_not_require_review_for_normal_failures(): void
    {
        $log = MediaProcessingLog::factory()->failed()->create([
            'current_step' => 'content_analysis',
            'error_message' => 'Temporary timeout occurred',
        ]);

        $this->assertFalse($this->service->requiresManualReview($log));
    }

    // ---- validateStorageConstraints ----

    #[Test]
    public function it_passes_storage_constraints_with_enough_space(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3', 1000);

        $errors = $this->service->validateStorageConstraints($file);

        $this->assertEmpty($errors);
    }

    #[Test]
    public function it_detects_compatibility_issues_in_storage_constraints(): void
    {
        $file = UploadedFile::fake()->create('sermon.wma', 1000);

        $errors = $this->service->validateStorageConstraints($file);

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'WMA files may have compatibility issues'))
        );
    }

    // ---- validateProcessingRequirements ----

    #[Test]
    public function it_detects_missing_storage_disk(): void
    {
        config(['media-processing.storage.sermon_disk' => 'nonexistent_disk']);
        config(['filesystems.disks.nonexistent_disk' => null]);

        $errors = $this->service->validateProcessingRequirements();

        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, "Storage disk 'nonexistent_disk' not configured"))
        );
    }

    #[Test]
    public function it_detects_missing_openai_key_when_required(): void
    {
        config(['services.openai.key' => null]);
        config(['media-processing.transcription.service' => 'openai']);

        $errors = $this->service->validateProcessingRequirements();

        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'OpenAI API key not configured'))
        );
    }

    #[Test]
    public function it_detects_missing_queue_configuration(): void
    {
        config(['queue.default' => null]);

        $errors = $this->service->validateProcessingRequirements();

        $this->assertTrue(
            collect($errors)->contains(fn ($e) => str_contains($e, 'Queue system not configured'))
        );
    }
}
