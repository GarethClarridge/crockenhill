<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Services\LegacySermonImporter;
use App\Services\Processing\MetadataExtractionService;
use App\Services\Processing\ProcessingRunOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LegacySermonImporterTest extends TestCase
{
    use RefreshDatabase;

    private LegacySermonImporter $importer;

    private ProcessingRunOrchestrator $orchestrator;

    private MetadataExtractionService $metadataExtractionService;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orchestrator = $this->createMock(ProcessingRunOrchestrator::class);
        $this->metadataExtractionService = $this->createMock(MetadataExtractionService::class);

        $this->importer = new LegacySermonImporter(
            $this->orchestrator,
            $this->metadataExtractionService
        );

        // Use a unique directory for each test run to avoid parallel collisions
        $this->tempDir = storage_path('app/temp_import_'.Str::uuid());
        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_imports_an_mp3_file_successfully(): void
    {
        $filename = 'ABC123.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');

        $csvIndex = [
            'abc123' => [
                'TapeID' => 'ABC123',
                'Date' => '2023-01-01',
                'Title' => 'Test Sermon',
                'Preacher' => 'John Doe',
                'AM/PM' => 'AM',
            ],
        ];

        $this->metadataExtractionService->expects($this->once())
            ->method('extractId3MetadataFromPath')
            ->willReturn([
                'title' => 'Test Sermon',
                'preacher' => 'John Doe',
                'series' => null,
                'reference' => null,
                'date' => '2023-01-01',
                'duration' => 1800.0,
            ]);

        $this->orchestrator->expects($this->once())
            ->method('start')
            ->with($this->isInstanceOf(MediaProcessingLog::class));

        $result = $this->importer->import($this->tempDir, $csvIndex, false, 0, false);

        $this->assertEquals(['imported' => 1, 'skipped' => 0, 'errors' => 0], $result);

        $this->assertDatabaseHas('media_processing_logs', [
            'original_filename' => $filename,
            'status' => ProcessingStatus::Pending,
            'extracted_date' => '2023-01-01 00:00:00',
            'extracted_service' => SermonService::Morning,
        ]);

        $log = MediaProcessingLog::where('original_filename', $filename)->first();
        $this->assertNotNull($log->source_file_path);
        $this->assertTrue(Storage::disk('public')->exists($log->source_file_path));
    }

    #[Test]
    public function it_skips_duplicate_files_by_hash(): void
    {
        $filename = 'ABC123.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');
        $fileHash = hash_file('sha256', $path);

        MediaProcessingLog::factory()->create([
            'file_hash' => $fileHash,
            'status' => ProcessingStatus::Completed,
        ]);

        $this->orchestrator->expects($this->never())->method('start');

        $result = $this->importer->import($this->tempDir, [], false, 0, false);

        $this->assertEquals(['imported' => 0, 'skipped' => 1, 'errors' => 0], $result);
    }

    #[Test]
    public function it_skips_duplicate_files_by_filename(): void
    {
        $filename = 'ABC123.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');

        MediaProcessingLog::factory()->create([
            'original_filename' => $filename,
            'status' => ProcessingStatus::Pending,
        ]);

        $this->orchestrator->expects($this->never())->method('start');

        $result = $this->importer->import($this->tempDir, [], false, 0, false);

        $this->assertEquals(['imported' => 0, 'skipped' => 1, 'errors' => 0], $result);
    }

    #[Test]
    public function it_forces_import_of_duplicates_when_requested(): void
    {
        $filename = 'ABC123.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');
        $fileHash = hash_file('sha256', $path);

        MediaProcessingLog::factory()->create([
            'file_hash' => $fileHash,
            'status' => ProcessingStatus::Completed,
        ]);

        $csvIndex = [
            'abc123' => [
                'Date' => '2023-01-01',
            ],
        ];

        $this->orchestrator->expects($this->once())->method('start');

        $this->importer->import($this->tempDir, $csvIndex, false, 0, true);

        $this->assertEquals(2, MediaProcessingLog::where('file_hash', $fileHash)->count());
    }

    #[Test]
    public function it_detects_metadata_conflicts(): void
    {
        $filename = 'ABC123.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');

        $csvIndex = [
            'abc123' => [
                'Title' => 'CSV Title',
                'Preacher' => 'CSV Preacher',
                'Date' => '2023-01-01',
            ],
        ];

        $this->metadataExtractionService->expects($this->once())
            ->method('extractId3MetadataFromPath')
            ->willReturn([
                'title' => 'ID3 Title',
                'preacher' => 'ID3 Preacher',
                'series' => null,
                'reference' => null,
                'date' => '2023-01-01',
                'duration' => 1800.0,
            ]);

        $progressReported = false;
        $onProgress = function ($file, $result, $conflicts) use (&$progressReported) {
            if ($result === 'conflict') {
                $progressReported = true;
                $this->assertCount(2, $conflicts);
            }
        };

        $this->importer->import($this->tempDir, $csvIndex, false, 0, false, $onProgress);

        $this->assertTrue($progressReported);

        $log = MediaProcessingLog::where('original_filename', $filename)->first();
        $this->assertArrayHasKey('metadata_conflicts', $log->processing_metadata);
        $this->assertCount(2, $log->processing_metadata['metadata_conflicts']);
    }

    #[Test]
    public function it_handles_dry_run_mode(): void
    {
        $filename = 'ABC123.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');

        $csvIndex = [
            'abc123' => [
                'Date' => '2023-01-01',
            ],
        ];

        $this->orchestrator->expects($this->never())->method('start');

        $result = $this->importer->import($this->tempDir, $csvIndex, true, 0, false);

        $this->assertEquals(['imported' => 1, 'skipped' => 0, 'errors' => 0], $result);
        $this->assertDatabaseMissing('media_processing_logs', ['original_filename' => $filename]);
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    #[Test]
    public function it_errors_when_no_date_is_found(): void
    {
        $filename = 'ABC123.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');

        // CSV row with no date
        $csvIndex = [
            'abc123' => [
                'Title' => 'Test',
            ],
        ];

        $result = $this->importer->import($this->tempDir, $csvIndex, false, 0, false);

        $this->assertEquals(['imported' => 0, 'skipped' => 0, 'errors' => 1], $result);
    }

    #[Test]
    public function it_normalises_tape_id_by_stripping_annotations(): void
    {
        $filename = 'ABC123 #comment#.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');

        $csvIndex = [
            'abc123' => [
                'Date' => '2023-01-01',
            ],
        ];

        $result = $this->importer->import($this->tempDir, $csvIndex, false, 0, false);

        $this->assertEquals(['imported' => 1, 'skipped' => 0, 'errors' => 0], $result);
        $this->assertDatabaseHas('media_processing_logs', ['original_filename' => $filename]);
    }

    #[Test]
    public function it_extracts_service_from_csv_am_pm(): void
    {
        $filename = 'ABC123.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');

        $csvIndex = [
            'abc123' => [
                'Date' => '2023-01-01',
                'AM/PM' => 'PM',
            ],
        ];

        $this->importer->import($this->tempDir, $csvIndex, false, 0, false);

        $this->assertDatabaseHas('media_processing_logs', [
            'original_filename' => $filename,
            'extracted_service' => SermonService::Evening,
        ]);
    }

    #[Test]
    public function it_extracts_duration_from_various_csv_formats(): void
    {
        $filename = 'ABC123.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');

        $csvIndex = [
            'abc123' => [
                'Date' => '2023-01-01',
                'Duration' => '01:05:30', // 3930 seconds
            ],
        ];

        $this->importer->import($this->tempDir, $csvIndex, false, 0, false);

        $this->assertDatabaseHas('media_processing_logs', [
            'original_filename' => $filename,
            'duration' => 3930.0,
        ]);
    }

    #[Test]
    public function it_handles_duration_in_mm_ss_format(): void
    {
        $filename = 'ABC123.mp3';
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, 'fake mp3 content');

        $csvIndex = [
            'abc123' => [
                'Date' => '2023-01-01',
                'Duration' => '45:30', // 2730 seconds
            ],
        ];

        $this->importer->import($this->tempDir, $csvIndex, false, 0, false);

        $this->assertDatabaseHas('media_processing_logs', [
            'original_filename' => $filename,
            'duration' => 2730.0,
        ]);
    }

    #[Test]
    public function it_discovers_mp3_files_recursively(): void
    {
        $subDir = $this->tempDir.'/subdir';
        mkdir($subDir);
        file_put_contents($this->tempDir.'/root.mp3', 'content A');
        file_put_contents($subDir.'/sub.mp3', 'content B');
        file_put_contents($this->tempDir.'/not_mp3.txt', 'content C');

        $csvIndex = [
            'root' => ['Date' => '2023-01-01'],
            'sub' => ['Date' => '2023-01-02'],
        ];

        $result = $this->importer->import($this->tempDir, $csvIndex, false, 0, false);

        $this->assertEquals(['imported' => 2, 'skipped' => 0, 'errors' => 0], $result);
    }
}
