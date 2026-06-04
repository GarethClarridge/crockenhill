<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonService;
use App\Models\MediaProcessingLog;
use App\Services\Processing\MetadataExtractionService;
use App\Services\Processing\ProcessingRunOrchestrator;
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

        $this->mock(MetadataExtractionService::class)
            ->shouldReceive('extractId3MetadataFromPath')
            ->zeroOrMoreTimes()
            ->andReturn([
                'title' => null,
                'preacher' => null,
                'series' => null,
                'reference' => null,
                'date' => null,
                'duration' => null,
            ]);

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
            $this->removeTemporaryDirectory($this->temporaryDirectory);
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
        $this->assertSame(SermonService::Morning, $log->extracted_service);
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
    public function it_rejects_imports_without_a_valid_historic_date(): void
    {
        $this->createFakeMp3('UNKNOWN999.mp3');

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory])
            ->assertExitCode(1)
            ->expectsOutputToContain('[error] UNKNOWN999.mp3');

        $this->assertDatabaseCount('media_processing_logs', 0);
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
        $csvPath = $this->createCsv([
            ['ABC123', '2024-03-10', 'AM', '45:00', 'Morning Sermon', 'Rev Smith', '', '', ''],
        ]);
        $existingHash = hash_file('sha256', $mp3Path);

        MediaProcessingLog::factory()->create([
            'file_hash' => $existingHash,
            'status' => ProcessingStatus::Completed,
        ]);

        $this->artisan('sermons:import-legacy', [
            '--dir' => $this->temporaryDirectory,
            '--csv' => $csvPath,
            '--force' => true,
        ])
            ->assertExitCode(0);

        $this->assertDatabaseCount('media_processing_logs', 2);
    }

    #[Test]
    public function it_warns_and_rejects_imports_when_csv_is_missing(): void
    {
        $this->createFakeMp3('ABC123.mp3');

        $this->artisan('sermons:import-legacy', [
            '--dir' => $this->temporaryDirectory,
            '--csv' => '/nonexistent/Tape Index.csv',
        ])
            ->assertExitCode(1)
            ->expectsOutputToContain('CSV not found')
            ->expectsOutputToContain('[error] ABC123.mp3');

        $this->assertDatabaseCount('media_processing_logs', 0);
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
    public function it_outputs_per_file_progress(): void
    {
        $this->createFakeMp3('ABC123.mp3');
        $csvPath = $this->createCsv([
            ['ABC123', '2024-03-10', 'AM', '45:00', 'Morning Sermon', 'Rev Smith', '', '', ''],
        ]);

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory, '--csv' => $csvPath])
            ->assertExitCode(0)
            ->expectsOutputToContain('[imported] ABC123.mp3');
    }

    #[Test]
    public function it_discovers_uppercase_mp3_extensions(): void
    {
        $this->createFakeMp3('UPPERCASE.MP3');
        $csvPath = $this->createCsv([
            ['UPPERCASE', '2024-03-10', 'AM', '45:00', 'Morning Sermon', 'Rev Smith', '', '', ''],
        ]);

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory, '--csv' => $csvPath])
            ->assertExitCode(0);

        $this->assertDatabaseCount('media_processing_logs', 1);

        $log = MediaProcessingLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('UPPERCASE.MP3', $log->original_filename);
    }

    #[Test]
    public function it_discovers_mp3_files_in_nested_directories(): void
    {
        $nestedDirectory = $this->temporaryDirectory.'/1-10';
        mkdir($nestedDirectory, 0755, true);

        $path = "{$nestedDirectory}/001a.mp3";
        file_put_contents($path, 'fake-mp3-content-'.uniqid());
        $this->temporaryFiles[] = $path;
        $csvPath = $this->createCsv([
            ['001A', '2003-08-31', 'AM', '35:05', 'Preach the Word', 'Bryan Martin', '', '', ''],
        ]);

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory, '--csv' => $csvPath])
            ->assertExitCode(0);

        $this->assertDatabaseCount('media_processing_logs', 1);

        $log = MediaProcessingLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('001a.mp3', $log->original_filename);
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
        $this->assertSame(SermonService::Evening, $log->extracted_service);
    }

    #[Test]
    public function it_matches_legacy_tape_ids_case_insensitively(): void
    {
        $this->createFakeMp3('001a.mp3');
        $csvPath = $this->createCsv([
            ['001A', '2003-08-31', 'AM', '35:05', 'Preach the Word', 'Bryan Martin', "The Pastor's Role", '2 Timothy', '3:14-4:5'],
        ]);

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory, '--csv' => $csvPath])
            ->assertExitCode(0);

        $log = MediaProcessingLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('001a.mp3', $log->original_filename);
        $this->assertSame('2003-08-31', $log->extracted_date?->toDateString());
        $this->assertSame(SermonService::Morning, $log->extracted_service);
        $this->assertSame(2105.0, $log->duration);
        $this->assertSame('Preach the Word', $log->processing_metadata?->id3Metadata?->title);
        $this->assertSame('Bryan Martin', $log->processing_metadata?->id3Metadata?->preacher);
        $this->assertSame("The Pastor's Role", $log->processing_metadata?->id3Metadata?->series);
        $this->assertSame('2 Timothy 3:14-4:5', $log->processing_metadata?->id3Metadata?->reference);
    }

    #[Test]
    public function it_reports_and_stores_embedded_metadata_conflicts_while_preserving_csv_values(): void
    {
        $mp3Path = $this->createFakeMp3('001a.mp3');
        $csvPath = $this->createCsv([
            ['001A', '2003-08-31', 'AM', '35:05', 'Preach the Word', 'Bryan Martin', "The Pastor's Role", '2 Timothy', '3:14-4:5'],
        ]);

        $this->mock(MetadataExtractionService::class)
            ->shouldReceive('extractId3MetadataFromPath')
            ->once()
            ->with($mp3Path)
            ->andReturn([
                'title' => 'Wrong Embedded Title',
                'preacher' => 'Unknown Artist',
                'series' => "The Pastor's Role",
                'reference' => '2 Timothy 3:14-4:5',
                'date' => '2004',
                'duration' => 2200.0,
            ]);

        $this->artisan('sermons:import-legacy', ['--dir' => $this->temporaryDirectory, '--csv' => $csvPath])
            ->assertExitCode(0)
            ->expectsOutputToContain('[conflict] 001a.mp3')
            ->expectsOutputToContain('[conflict] title: CSV "Preach the Word" differs from MP3 "Wrong Embedded Title"')
            ->expectsOutputToContain('[conflict] preacher: CSV "Bryan Martin" differs from MP3 "Unknown Artist"')
            ->expectsOutputToContain('[conflict] date: CSV "2003-08-31" differs from MP3 "2004"');

        $log = MediaProcessingLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('2003-08-31', $log->extracted_date?->toDateString());
        $this->assertSame(SermonService::Morning, $log->extracted_service);
        // Title: CSV wins
        $this->assertSame('Preach the Word', $log->processing_metadata?->id3Metadata?->title);
        // Preacher: MP3 wins
        $this->assertSame('Unknown Artist', $log->processing_metadata?->id3Metadata?->preacher);

        $metadata = $log->processing_metadata?->toArray() ?? [];
        $this->assertSame('Wrong Embedded Title', $metadata['embedded_id3_metadata']['title'] ?? null);
        // Duration conflicts are suppressed — not included in metadata_conflicts
        $this->assertEquals([
            [
                'field' => 'title',
                'csv' => 'Preach the Word',
                'embedded' => 'Wrong Embedded Title',
            ],
            [
                'field' => 'preacher',
                'csv' => 'Bryan Martin',
                'embedded' => 'Unknown Artist',
            ],
            [
                'field' => 'date',
                'csv' => '2003-08-31',
                'embedded' => '2004',
            ],
        ], $metadata['metadata_conflicts'] ?? null);
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

    private function removeTemporaryDirectory(string $directory): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            if ($item->isFile()) {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
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
