<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Services\ProcessingRunOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportLegacySermonBatchCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/legacy-sermon-import-test-'.uniqid();
        mkdir($this->temporaryDirectory, 0755, true);

        $this->mock(ProcessingRunOrchestrator::class)
            ->shouldReceive('start')
            ->andReturn();

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (is_dir($this->temporaryDirectory)) {
            array_map('unlink', glob($this->temporaryDirectory.'/*') ?: []);
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_fails_when_dir_option_is_missing(): void
    {
        $this->artisan('sermons:import-legacy')
            ->assertExitCode(1)
            ->expectsOutputToContain('--dir=');
    }

    #[Test]
    public function it_fails_when_dir_does_not_exist(): void
    {
        $this->artisan('sermons:import-legacy', ['--dir' => '/nonexistent/path/abc123'])
            ->assertExitCode(1)
            ->expectsOutputToContain('does not exist');
    }

    #[Test]
    public function it_imports_an_mp3_and_creates_a_processing_log(): void
    {
        $mp3Path = $this->createFakeMp3('ABC123.mp3');
        $csvPath = $this->createCsv([
            ['ABC123', '2024-03-10', 'AM', '45:00', 'Morning Sermon', 'Rev Smith', 'Grace Series', 'John', '3:16'],
        ]);

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory, '--csv' => $csvPath])
            ->assertExitCode(0)
            ->expectsOutputToContain('Legacy sermon import completed.');

        $this->assertDatabaseCount('media_processing_logs', 1);

        $log = MediaProcessingLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('ABC123.mp3', $log->original_filename);
        $this->assertSame(MediaType::Audio, $log->processing_type);
        $this->assertSame(ProcessingStatus::Pending, $log->status);
        $this->assertSame('2024-03-10', $log->extracted_date?->toDateString());
        $this->assertSame(SermonService::MORNING, $log->extracted_service);
        $this->assertSame(2700.0, $log->duration);
        $this->assertNotNull($log->file_hash);
        $this->assertNotNull($log->file_size);

        $id3 = $log->processing_metadata?->id3Metadata;
        $this->assertNotNull($id3);
        $this->assertSame('Morning Sermon', $id3->title);
        $this->assertSame('Rev Smith', $id3->preacher);
        $this->assertSame('Grace Series', $id3->series);
        $this->assertSame('John 3:16', $id3->reference);

        // Verify the file was stored on the configured disk
        $this->assertNotNull($log->source_file_path);
        Storage::disk('public')->assertExists($log->source_file_path);
    }

    #[Test]
    public function it_imports_without_csv_match_and_starts_processing(): void
    {
        $this->createFakeMp3('UNKNOWN999.mp3');

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory])
            ->assertExitCode(0);

        $this->assertDatabaseCount('media_processing_logs', 1);

        $log = MediaProcessingLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('UNKNOWN999.mp3', $log->original_filename);
        $this->assertNull($log->extracted_date);
        $this->assertNull($log->extracted_service);
        $this->assertNull($log->processing_metadata?->id3Metadata);
    }

    #[Test]
    public function dry_run_does_not_store_files_or_create_logs(): void
    {
        $this->createFakeMp3('ABC123.mp3');
        $csvPath = $this->createCsv([
            ['ABC123', '2024-03-10', 'AM', '45:00', 'Morning Sermon', 'Rev Smith', '', '', ''],
        ]);

        $this->artisan('sermons:import-legacy', [
            '--dir' => $this->temporaryDirectory,
            '--csv' => $csvPath,
            '--dry-run' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Dry run enabled');

        $this->assertDatabaseCount('media_processing_logs', 0);
    }

    #[Test]
    public function it_skips_duplicate_file_hash(): void
    {
        $mp3Path = $this->createFakeMp3('ABC123.mp3');
        $existingHash = hash_file('sha256', $mp3Path);

        MediaProcessingLog::factory()->create([
            'file_hash' => $existingHash,
            'status' => ProcessingStatus::Completed,
        ]);

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory])
            ->assertExitCode(0);

        // Still only the pre-existing log
        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    #[Test]
    public function it_skips_duplicate_original_filename(): void
    {
        $this->createFakeMp3('ABC123.mp3');

        MediaProcessingLog::factory()->create([
            'original_filename' => 'ABC123.mp3',
            'file_hash' => 'different-hash-value',
            'status' => ProcessingStatus::Pending,
        ]);

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory])
            ->assertExitCode(0);

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    #[Test]
    public function force_flag_reimports_duplicate_files(): void
    {
        $mp3Path = $this->createFakeMp3('ABC123.mp3');
        $existingHash = hash_file('sha256', $mp3Path);

        MediaProcessingLog::factory()->create([
            'file_hash' => $existingHash,
            'status' => ProcessingStatus::Completed,
        ]);

        $this->artisan('sermons:import-legacy', [
            '--dir' => $this->temporaryDirectory,
            '--force' => true,
        ])
            ->assertExitCode(0);

        $this->assertDatabaseCount('media_processing_logs', 2);
    }

    #[Test]
    public function it_proceeds_with_warnings_when_csv_is_missing(): void
    {
        $this->createFakeMp3('ABC123.mp3');

        $this->artisan('sermons:import-legacy', [
            '--dir' => $this->temporaryDirectory,
            '--csv' => '/nonexistent/Tape Index.csv',
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('CSV not found');

        $this->assertDatabaseCount('media_processing_logs', 1);
    }

    #[Test]
    public function it_only_writes_non_empty_metadata_fields(): void
    {
        $this->createFakeMp3('ABC123.mp3');
        $csvPath = $this->createCsv([
            ['ABC123', '2024-03-10', 'AM', '', 'Title Only', '', '', '', ''],
        ]);

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory, '--csv' => $csvPath])
            ->assertExitCode(0);

        $log = MediaProcessingLog::query()->first();
        $this->assertNotNull($log);

        $id3 = $log->processing_metadata?->id3Metadata;
        $this->assertNotNull($id3);
        $this->assertSame('Title Only', $id3->title);
        $this->assertNull($id3->preacher);
        $this->assertNull($id3->series);
        $this->assertNull($id3->reference);
        $this->assertNull($log->duration);
    }

    #[Test]
    public function it_strips_tape_annotation_for_csv_lookup(): void
    {
        $this->createFakeMp3('ABC123 #duplicate#.mp3');
        $csvPath = $this->createCsv([
            ['ABC123', '2024-03-10', 'PM', '30:00', 'Evening Talk', 'Rev Jones', '', '', ''],
        ]);

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory, '--csv' => $csvPath])
            ->assertExitCode(0);

        $log = MediaProcessingLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('Evening Talk', $log->processing_metadata?->id3Metadata?->title);
        $this->assertSame(SermonService::EVENING, $log->extracted_service);
    }

    /**
     * Create a minimal fake MP3 file and register it for teardown cleanup.
     */
    private function createFakeMp3(string $filename): string
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path, 'fake-mp3-content-'.uniqid());
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /**
     * Create a CSV fixture with the standard tape index headers.
     *
     * Each row: [TapeID, Date, AM/PM, Duration, Title, Preacher, Series, Book, Reference]
     *
     * @param  list<list<string>>  $rows
     */
    private function createCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tape-index-');

        if ($path === false) {
            self::fail('Failed to allocate a temporary CSV file.');
        }

        $this->temporaryFiles[] = $path;

        $handle = fopen($path, 'w');

        if ($handle === false) {
            self::fail('Failed to open temporary CSV file for writing.');
        }

        fputcsv($handle, ['Tape ID', 'Date', 'AM/PM', 'Duration', 'Title', 'Preacher', 'Series', 'Book', 'Reference']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return $path;
    }
}
