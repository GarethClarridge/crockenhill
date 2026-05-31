<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SermonSourceType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use App\Services\MediaValidationService;
use App\Services\SermonValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonValidationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private SermonValidationService $service;

    private MediaValidationService&MockInterface $mediaValidation;

    private SermonRepository&MockInterface $sermonRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaValidation = Mockery::mock(MediaValidationService::class);
        $this->sermonRepository = Mockery::mock(SermonRepository::class);

        $this->service = new SermonValidationService(
            $this->mediaValidation,
            $this->sermonRepository
        );
    }

    #[Test]
    public function it_can_be_instantiated(): void
    {
        $this->assertInstanceOf(SermonValidationService::class, $this->service);
    }

    #[Test]
    public function validate_audio_file_delegates_to_media_validation(): void
    {
        $file = UploadedFile::fake()->create('sermon.mp3');
        $this->mediaValidation->shouldReceive('validateUploadedFile')
            ->once()
            ->with(\App\Enums\MediaType::Audio, $file);

        $this->service->validateAudioFile($file);
    }

    #[Test]
    public function validate_processing_metadata_returns_no_errors_for_valid_data(): void
    {
        $metadata = [
            'source_type' => SermonSourceType::AudioUpload->value,
            'original_filename' => 'sermon.mp3',
        ];

        $errors = $this->service->validateProcessingMetadata($metadata);

        $this->assertEmpty($errors);
    }

    #[Test]
    public function validate_processing_metadata_detects_missing_fields(): void
    {
        $errors = $this->service->validateProcessingMetadata([]);

        $this->assertContains('Source type is required', $errors);
        $this->assertContains('Original filename is required', $errors);
    }

    #[Test]
    public function validate_processing_metadata_detects_invalid_source_type(): void
    {
        $metadata = [
            'source_type' => 'invalid',
            'original_filename' => 'sermon.mp3',
        ];

        $errors = $this->service->validateProcessingMetadata($metadata);

        $this->assertContains('Invalid source type. Must be one of: manual, audio_upload, video_upload, livestream', $errors);
    }

    #[Test]
    public function validate_processing_metadata_detects_dangerous_filenames(): void
    {
        $dangerousNames = [
            '../../etc/passwd',
            'folder/file.mp3',
            'folder\file.mp3',
            'http://example.com/file.mp3',
        ];

        foreach ($dangerousNames as $name) {
            $errors = $this->service->validateProcessingMetadata([
                'source_type' => SermonSourceType::AudioUpload->value,
                'original_filename' => $name,
            ]);

            $this->assertContains('Filename contains invalid characters', $errors, "Failed for filename: $name");
        }
    }

    #[Test]
    public function validate_processing_metadata_detects_long_filenames(): void
    {
        $longName = str_repeat('a', 256).'.mp3';
        $errors = $this->service->validateProcessingMetadata([
            'source_type' => SermonSourceType::AudioUpload->value,
            'original_filename' => $longName,
        ]);

        $this->assertContains('Filename too long (maximum 255 characters)', $errors);
    }

    #[Test]
    public function generate_fallback_title_extracts_title_from_filename(): void
    {
        $sermon = Sermon::factory()->make();
        $log = MediaProcessingLog::factory()->make([
            'original_filename' => '2024-05-20_the_great_commission.mp3',
        ]);

        $title = $this->service->generateFallbackTitle($sermon, $log);

        $this->assertEquals('The Great Commission', $title);
    }

    #[Test]
    public function generate_fallback_title_uses_date_fallback_when_filename_invalid(): void
    {
        $date = \Carbon\Carbon::parse('2024-05-20');
        $sermon = Sermon::factory()->make([
            'date' => $date,
            'service' => \App\Enums\SermonService::Morning,
        ]);
        $log = MediaProcessingLog::factory()->make([
            'original_filename' => 'abc.mp3', // Too short after processing
        ]);

        $title = $this->service->generateFallbackTitle($sermon, $log);

        $this->assertEquals('Sermon - May 20, 2024 morning', $title);
    }

    #[Test]
    public function generate_fallback_data_triggers_fallback_for_generic_titles(): void
    {
        $sermon = Sermon::factory()->make([
            'title' => 'Untitled Sermon',
            'id' => 123,
        ]);
        $log = MediaProcessingLog::factory()->make([
            'original_filename' => '2024-05-20_test_sermon.mp3',
        ]);

        $this->sermonRepository->shouldReceive('generateUniqueSlug')
            ->once()
            ->with('Test Sermon', 123)
            ->andReturn('test-sermon');

        $data = $this->service->generateFallbackData($sermon, $log);

        $this->assertEquals('Test Sermon', $data['title']);
        $this->assertEquals('test-sermon', $data['slug']);
        $this->assertNull($data['series']);
        $this->assertNull($data['reference']);
        $this->assertEquals(['Main Message'], $data['points']);
    }

    #[Test]
    public function validate_sermon_data_detects_errors(): void
    {
        // 1. Missing required fields
        $errors = $this->service->validateSermonData([
            'title' => '',
            'date' => '',
        ]);
        $this->assertContains('Sermon title is required', $errors);
        $this->assertContains('Sermon date is required', $errors);

        // 2. Long fields
        $errors = $this->service->validateSermonData([
            'title' => str_repeat('a', 256),
            'date' => '2024-05-20',
            'preacher' => str_repeat('a', 101),
            'series' => str_repeat('a', 101),
            'reference' => str_repeat('a', 256),
        ]);
        $this->assertContains('Sermon title too long (maximum 255 characters)', $errors);
        $this->assertContains('Preacher name too long (maximum 100 characters)', $errors);
        $this->assertContains('Series name too long (maximum 100 characters)', $errors);
        $this->assertContains('Bible reference too long (maximum 255 characters)', $errors);

        // 3. Invalid formats
        $errors = $this->service->validateSermonData([
            'title' => 'Valid Title',
            'date' => 'invalid-date',
            'service' => 'invalid-service',
            'slug' => 'Invalid Slug!',
        ]);
        $this->assertContains('Invalid sermon date format', $errors);
        $this->assertContains('Invalid service type. Must be one of: morning, evening, other', $errors);
        $this->assertContains('Slug can only contain lowercase letters, numbers, and hyphens', $errors);

        // 4. Duplicate slug
        Sermon::factory()->create(['slug' => 'existing-slug']);
        $errors = $this->service->validateSermonData([
            'title' => 'New Sermon',
            'date' => '2024-05-20',
            'slug' => 'existing-slug',
        ]);
        $this->assertContains('Slug already exists - must be unique', $errors);
    }

    #[Test]
    public function validate_storage_constraints_detects_compatibility_issues(): void
    {
        $testCases = [
            'audio.wma' => 'WMA files may have compatibility issues - consider converting to MP3',
            'audio.flac' => 'FLAC files are large - consider MP3 for better processing performance',
            'audio.aac' => 'AAC files may require additional processing time',
        ];

        foreach ($testCases as $filename => $expectedError) {
            $file = UploadedFile::fake()->create($filename, 1024);
            $errors = $this->service->validateStorageConstraints($file);
            $this->assertContains($expectedError, $errors);
        }
    }

    #[Test]
    public function validate_processing_requirements_detects_missing_config(): void
    {
        // 1. Missing OpenAI key when required
        Config::set('media-processing.transcription.service', 'openai');
        Config::set('services.openai.key', null);

        $errors = $this->service->validateProcessingRequirements();
        $this->assertContains('OpenAI API key not configured but required for transcription', $errors);

        // 2. Missing queue configuration
        Config::set('queue.default', null);
        $errors = $this->service->validateProcessingRequirements();
        $this->assertContains('Queue system not configured - required for processing jobs', $errors);

        // 3. Missing storage disk
        Config::set('media-processing.storage.sermon_disk', 'nonexistent_disk');
        Config::set('filesystems.disks.nonexistent_disk', null);
        $errors = $this->service->validateProcessingRequirements();
        $this->assertContains("Storage disk 'nonexistent_disk' not configured", $errors);
    }

    #[Test]
    public function can_retry_processing_returns_correct_status(): void
    {
        // 1. Can retry happy path
        $log = MediaProcessingLog::factory()->make([
            'current_step' => 'transcribing_audio',
            'created_at' => now(),
        ]);
        $this->assertTrue($this->service->canRetryProcessing($log));

        // 2. Cannot retry manual review
        $log->current_step = 'manual_review_required';
        $this->assertFalse($this->service->canRetryProcessing($log));

        // 3. Cannot retry old logs
        $log->current_step = 'transcribing_audio';
        $log->created_at = now()->subDays(8);
        $this->assertFalse($this->service->canRetryProcessing($log));

        // 4. Cannot retry critical failures
        $log->created_at = now();
        $log->error_message = 'storage_failure: Disk full';
        $this->assertFalse($this->service->canRetryProcessing($log));
    }

    #[Test]
    public function requires_manual_review_returns_correct_status(): void
    {
        // 1. Happy path no review
        $log = MediaProcessingLog::factory()->make([
            'current_step' => 'processing_complete',
        ]);
        $this->assertFalse($this->service->requiresManualReview($log));

        // 2. Step name triggers review
        $log->current_step = 'manual_review_pending';
        $this->assertTrue($this->service->requiresManualReview($log));

        // 3. Critical steps trigger review
        $log->current_step = 'creating_sermon_record';
        $this->assertTrue($this->service->requiresManualReview($log));

        // 4. Error patterns trigger review
        $log->current_step = 'some_step';
        $log->error_message = 'database constraint violation occurred';
        $this->assertTrue($this->service->requiresManualReview($log));
    }
}
