<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonService;
use App\Models\ChurchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class ImportOpenLpDirectoryCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_imports_every_openlp_archive_in_a_directory(): void
    {
        $directory = $this->makeTemporaryDirectory();

        $this->writeArchiveToDirectory(
            $directory,
            OpenLpArchiveFactory::makeUpload(
                archiveName: '2024-11-17 AM.osz',
                osjName: '2024-11-17 AM.osj',
                payload: OpenLpArchiveFactory::payload([
                    OpenLpArchiveFactory::serviceItem(
                        OpenLpArchiveFactory::songHeader('Morning Song', 'morning song@')
                    ),
                ]),
            ),
        );

        $this->writeArchiveToDirectory(
            $directory,
            OpenLpArchiveFactory::makeUpload(
                archiveName: '2024-11-17 PM.osz',
                osjName: '2024-11-17 PM.osj',
                payload: OpenLpArchiveFactory::payload([
                    OpenLpArchiveFactory::serviceItem(
                        OpenLpArchiveFactory::customHeader('Evening Reading')
                    ),
                ]),
            ),
        );

        $this->artisan('service-tracking:import-openlp-services', ['--path' => $directory])
            ->assertSuccessful()
            ->expectsOutputToContain('OpenLP archive import completed.');

        $this->assertDatabaseCount('church_services', 2);
        $this->assertDatabaseHas('church_services', [
            'date' => '2024-11-17',
            'service' => SermonService::MORNING->value,
            'original_filename' => '2024-11-17 AM.osz',
        ]);
        $this->assertDatabaseHas('church_services', [
            'date' => '2024-11-17',
            'service' => SermonService::EVENING->value,
            'original_filename' => '2024-11-17 PM.osz',
        ]);
    }

    #[Test]
    public function it_updates_existing_services_when_the_command_is_re_run(): void
    {
        $directory = $this->makeTemporaryDirectory();

        $this->writeArchiveToDirectory(
            $directory,
            OpenLpArchiveFactory::makeUpload(
                archiveName: '2024-11-17 AM.osz',
                osjName: '2024-11-17 AM.osj',
                payload: OpenLpArchiveFactory::payload([
                    OpenLpArchiveFactory::serviceItem(
                        OpenLpArchiveFactory::songHeader('Original Song', 'original song@')
                    ),
                ]),
            ),
        );

        $this->artisan('service-tracking:import-openlp-services', ['--path' => $directory])
            ->assertSuccessful();

        $this->writeArchiveToDirectory(
            $directory,
            OpenLpArchiveFactory::makeUpload(
                archiveName: '2024-11-17 AM.osz',
                osjName: '2024-11-17 AM.osj',
                payload: OpenLpArchiveFactory::payload([
                    OpenLpArchiveFactory::serviceItem(
                        OpenLpArchiveFactory::songHeader('Updated Song', 'updated song@')
                    ),
                    OpenLpArchiveFactory::serviceItem(
                        OpenLpArchiveFactory::customHeader('Reading')
                    ),
                ]),
            ),
        );

        $this->artisan('service-tracking:import-openlp-services', ['--path' => $directory])
            ->assertSuccessful();

        $this->assertDatabaseCount('church_services', 1);

        $service = ChurchService::query()
            ->with(['items' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->firstOrFail();

        $this->assertCount(2, $service->items);
        $this->assertSame('Updated Song', $service->items[0]->title);
        $this->assertSame('Reading', $service->items[1]->title);
    }

    #[Test]
    public function it_continues_past_invalid_archives_and_reports_a_failure_exit_code(): void
    {
        $directory = $this->makeTemporaryDirectory();

        $this->writeArchiveToDirectory(
            $directory,
            OpenLpArchiveFactory::makeUpload(
                archiveName: '2024-11-17 AM.osz',
                osjName: '2024-11-17 AM.osj',
            ),
        );

        file_put_contents($directory.'/broken.osz', 'not a zip archive');

        $this->artisan('service-tracking:import-openlp-services', ['--path' => $directory])
            ->assertFailed()
            ->expectsOutputToContain('broken.osz');

        $this->assertDatabaseCount('church_services', 1);
    }

    #[Test]
    public function it_supports_a_dry_run_without_persisting_imported_services(): void
    {
        $directory = $this->makeTemporaryDirectory();

        $this->writeArchiveToDirectory(
            $directory,
            OpenLpArchiveFactory::makeUpload(
                archiveName: '2024-11-17 AM.osz',
                osjName: '2024-11-17 AM.osj',
            ),
        );

        $this->artisan('service-tracking:import-openlp-services', ['--path' => $directory, '--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run enabled. No database changes were written.');

        $this->assertDatabaseCount('church_services', 0);
    }

    #[Test]
    public function it_fails_when_no_openlp_archives_are_present(): void
    {
        $directory = $this->makeTemporaryDirectory();

        file_put_contents($directory.'/notes.txt', 'No archives here');

        $this->artisan('service-tracking:import-openlp-services', ['--path' => $directory])
            ->assertFailed()
            ->expectsOutputToContain('No .osz archives were found');
    }

    private function makeTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/openlp-import-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            self::fail('Failed to create temporary directory.');
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function writeArchiveToDirectory(string $directory, \Illuminate\Http\UploadedFile $archive): void
    {
        $targetPath = $directory.'/'.$archive->getClientOriginalName();

        if (! copy($archive->getPathname(), $targetPath)) {
            self::fail("Failed to write archive to {$targetPath}");
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);

        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            if (is_dir($path)) {
                $this->deleteDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
