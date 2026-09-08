<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestageHistoricSourceCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET = 'livestream/temp/restored.mp4';

    private const CONTENT = 'the recording this run processed';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
    }

    #[Test]
    public function it_restores_a_byte_identical_archive_copy(): void
    {
        $log = $this->processingRun(hash('sha256', self::CONTENT));
        $archive = $this->archive(self::CONTENT);

        $this->artisan('historic-import:restage-source', ['run' => $log->id, 'source' => $archive, '--execute' => true])
            ->expectsOutputToContain('byte-identical')
            ->expectsOutputToContain('Restored and verified.')
            ->assertSuccessful();

        Storage::disk('local')->assertExists(self::TARGET);
        self::assertSame(self::CONTENT, Storage::disk('local')->get(self::TARGET));
    }

    #[Test]
    public function it_verifies_without_writing_by_default(): void
    {
        $log = $this->processingRun(hash('sha256', self::CONTENT));

        $this->artisan('historic-import:restage-source', ['run' => $log->id, 'source' => $this->archive(self::CONTENT)])
            ->expectsOutputToContain('VERIFY ONLY')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing(self::TARGET);
    }

    #[Test]
    public function it_refuses_a_recording_of_the_same_service_that_is_a_different_file(): void
    {
        $log = $this->processingRun(hash('sha256', self::CONTENT));

        // The sections are timed against the original, so cutting them from a
        // different capture would misplace every one of them.
        $this->artisan('historic-import:restage-source', [
            'run' => $log->id,
            'source' => $this->archive('a different capture of the same service'),
            '--execute' => true,
        ])
            ->expectsOutputToContain('not the recording this run processed')
            ->assertFailed();

        Storage::disk('local')->assertMissing(self::TARGET);
    }

    #[Test]
    public function it_refuses_a_run_that_recorded_no_hash(): void
    {
        $log = $this->processingRun(null);

        $this->artisan('historic-import:restage-source', [
            'run' => $log->id,
            'source' => $this->archive(self::CONTENT),
            '--execute' => true,
        ])
            ->expectsOutputToContain('recorded no file hash')
            ->assertFailed();

        Storage::disk('local')->assertMissing(self::TARGET);
    }

    #[Test]
    public function it_leaves_an_already_present_source_alone(): void
    {
        $log = $this->processingRun(hash('sha256', self::CONTENT));
        Storage::disk('local')->put(self::TARGET, 'the copy already staged');

        $this->artisan('historic-import:restage-source', [
            'run' => $log->id,
            'source' => $this->archive(self::CONTENT),
            '--execute' => true,
        ])
            ->expectsOutputToContain('already present')
            ->assertSuccessful();

        self::assertSame('the copy already staged', Storage::disk('local')->get(self::TARGET));
    }

    private function processingRun(?string $fileHash): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->livestream()->completed()->create([
            'source_file_path' => self::TARGET,
            'file_hash' => $fileHash,
        ]);
    }

    private function archive(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'restage');
        file_put_contents($path, $content);

        return $path;
    }
}
