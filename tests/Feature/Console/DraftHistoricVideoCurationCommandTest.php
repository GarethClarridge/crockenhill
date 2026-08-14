<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Media\Video\HistoricVideoCurationManifest;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DraftHistoricVideoCurationCommandTest extends TestCase
{
    private string $rawRoot;

    private string $worksheetPath;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rawRoot = sys_get_temp_dir().'/historic-video-draft-'.Str::random(12);
        mkdir($this->rawRoot, 0755, true);

        // The suite's private storage directory is untracked, so it may not
        // exist on a clean checkout or on CI.
        if (! is_dir(storage_path('app/private'))) {
            mkdir(storage_path('app/private'), 0755, true);
        }

        $this->worksheetPath = 'historic-video-worksheet-'.Str::random(12).'.json';
        $this->manifestPath = 'historic-video-manifest-'.Str::random(12).'.json';
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rawRoot);

        foreach ([$this->worksheetPath, $this->manifestPath] as $relative) {
            $written = storage_path('app/private/'.$relative);

            if (is_file($written)) {
                unlink($written);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_drafts_a_worksheet_without_reading_file_contents(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');
        $this->recording('2021-01-17', 'Evening', 'evening.mp4');

        $this->draft()->assertSuccessful();

        $worksheet = $this->readPrivate($this->worksheetPath);

        $this->assertSame('crockenhill-historic-video-curation-worksheet', $worksheet['format']);
        $this->assertSame(1, $worksheet['version']);
        $this->assertSame('video-draft-test', $worksheet['batch_key']);
        $this->assertCount(2, $worksheet['entries']);

        // Hashing is what stage two is for; a worksheet that carried hashes
        // would have cost a full corpus read to produce.
        foreach ($worksheet['entries'] as $entry) {
            foreach ($entry['files'] as $file) {
                $this->assertArrayNotHasKey('sha256', $file);
                $this->assertArrayHasKey('byte_size', $file);
            }
        }
    }

    #[Test]
    public function it_derives_service_identity_from_the_corpus_layout(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');
        $this->recording('2021-01-10', 'Evening', 'service.mp4');

        $this->draft()->assertSuccessful();

        $entries = collect($this->readPrivate($this->worksheetPath)['entries'])->keyBy('item_key');

        $this->assertSame('morning', $entries['2021-01-10-morning']['service']);
        $this->assertSame('evening', $entries['2021-01-10-evening']['service']);
        $this->assertSame('2021-01-10', $entries['2021-01-10-morning']['date']);
    }

    #[Test]
    public function it_groups_multiple_recordings_of_one_service_into_a_concatenation(): void
    {
        $this->recording('2020-03-29', 'Morning', 'welcome.mp4');
        $this->recording('2020-03-29', 'Morning', 'sermon.mp4');
        $this->recording('2020-03-29', 'Morning', 'benediction.mp4');

        $this->draft()->assertSuccessful();

        $entry = $this->readPrivate($this->worksheetPath)['entries'][0];

        $this->assertSame('lossless', $entry['concatenation']);
        $this->assertSame(3, $entry['expected_occurrence_count']);
        $this->assertCount(3, $entry['files']);
        $this->assertSame('fragmented', $entry['corroboration']);
    }

    #[Test]
    public function it_grades_a_single_unprobed_recording_as_unknown(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');

        $this->draft(skipProbe: true)->assertSuccessful();

        $this->assertSame('unknown', $this->readPrivate($this->worksheetPath)['entries'][0]['corroboration']);
    }

    #[Test]
    public function it_recovers_the_duration_of_a_container_whose_header_declares_none(): void
    {
        // Roughly a tenth of the real corpus is WebM pulled down as YouTube
        // backups, and those carry no duration in the format or stream header.
        // Graded naively they are all "unknown", which would quietly drop 35
        // full-length services out of corroboration.
        $directory = "{$this->rawRoot}/2020-09-06/Morning";
        mkdir($directory, 0755, true);
        $this->streamedWebm("{$directory}/backup.webm", seconds: 3);

        $this->draft()->assertSuccessful();

        $file = $this->readPrivate($this->worksheetPath)['entries'][0]['files'][0];

        $this->assertNotNull($file['duration_minutes']);
        $this->assertEqualsWithDelta(0.05, $file['duration_minutes'], 0.02);
    }

    #[Test]
    public function it_refuses_recordings_with_no_derivable_service_identity(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');
        mkdir($this->rawRoot.'/loose', 0755, true);
        file_put_contents($this->rawRoot.'/loose/orphan.mp4', 'x');

        $this->draft()
            ->expectsOutputToContain('no derivable date and service')
            ->assertFailed();

        $this->assertFileDoesNotExist(storage_path('app/private/'.$this->worksheetPath));
    }

    #[Test]
    public function it_ignores_removable_drive_metadata(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');
        file_put_contents($this->rawRoot.'/2021-01-10/Morning/._service.mp4', 'apple double');
        file_put_contents($this->rawRoot.'/2021-01-10/Morning/.DS_Store', 'finder');
        file_put_contents($this->rawRoot.'/2021-01-10/Morning/notes.txt', 'not a recording');

        $this->draft()->assertSuccessful();

        $entries = $this->readPrivate($this->worksheetPath)['entries'];

        $this->assertCount(1, $entries);
        $this->assertCount(1, $entries[0]['files']);
    }

    #[Test]
    public function it_requires_an_explicit_batch_key_and_rule_version(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');

        $this->artisan('historic-import:draft-video-curation', [
            'root' => $this->rawRoot,
            '--worksheet' => $this->worksheetPath,
            '--rule-version' => 'video-curation-v1',
        ])->expectsOutputToContain('--batch-key')->assertFailed();

        $this->artisan('historic-import:draft-video-curation', [
            'root' => $this->rawRoot,
            '--worksheet' => $this->worksheetPath,
            '--batch-key' => 'video-draft-test',
        ])->expectsOutputToContain('--rule-version')->assertFailed();
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_worksheet(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');

        $this->draft()->assertSuccessful();
        $this->draft()->assertFailed();
    }

    #[Test]
    public function capture_freezes_an_adjudicated_worksheet_into_a_valid_manifest(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');
        $this->recording('2021-01-17', 'Evening', 'evening.mp4');

        $this->draft()->assertSuccessful();
        $this->capture()->assertSuccessful();

        $manifest = $this->readPrivate($this->manifestPath);

        $this->assertSame('crockenhill-historic-video-curation', $manifest['format']);
        $this->assertSame(4, $manifest['version']);
        $this->assertSame('video-draft-test', $manifest['batch_key']);

        foreach ($manifest['entries'] as $entry) {
            foreach ($entry['files'] as $file) {
                $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $file['sha256']);
                $this->assertArrayNotHasKey('duration_minutes', $file);
            }
        }

        // The producer's whole purpose is output the validator accepts.
        $plan = app(HistoricVideoCurationManifest::class)->plan(
            $this->rawRoot,
            storage_path('app/private/'.$this->manifestPath),
        );

        $this->assertSame(2, $plan->counts['include']);
        $this->assertSame(0, $plan->counts['exclude']);
    }

    #[Test]
    public function capture_preserves_the_operators_adjudication(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');
        $this->recording('2021-01-17', 'Evening', 'evening.mp4');

        $this->draft()->assertSuccessful();

        $worksheet = $this->readPrivate($this->worksheetPath);
        $worksheet['entries'][1]['disposition'] = 'exclude';
        $worksheet['entries'][1]['exclusion_reason'] = 'Recorded in error; no service took place.';
        $worksheet['entries'][0]['editorial_facts']['speaker'] = 'Ruth';
        $this->writePrivate($this->worksheetPath, $worksheet);

        $this->capture()->assertSuccessful();

        $entries = $this->readPrivate($this->manifestPath)['entries'];

        $this->assertSame('exclude', $entries[1]['disposition']);
        $this->assertSame('Recorded in error; no service took place.', $entries[1]['exclusion_reason']);
        $this->assertSame('Ruth', $entries[0]['editorial_facts']['speaker']);
    }

    #[Test]
    public function capture_refuses_a_corpus_that_moved_since_drafting(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');

        $this->draft()->assertSuccessful();

        file_put_contents(
            $this->rawRoot.'/2021-01-10/Morning/service.mp4',
            'a different recording entirely, of a different length',
        );

        $this->capture()
            ->expectsOutputToContain('changed size since drafting')
            ->assertFailed();
    }

    #[Test]
    public function capture_refuses_a_recording_deleted_since_drafting(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');

        $this->draft()->assertSuccessful();

        unlink($this->rawRoot.'/2021-01-10/Morning/service.mp4');

        $this->capture()
            ->expectsOutputToContain('missing since drafting')
            ->assertFailed();
    }

    #[Test]
    public function capture_refuses_anything_that_is_not_a_worksheet(): void
    {
        $this->recording('2021-01-10', 'Morning', 'service.mp4');

        $this->writePrivate($this->worksheetPath, [
            'format' => 'crockenhill-historic-video-curation',
            'version' => 4,
            'batch_key' => 'already-frozen',
            'entries' => [],
        ]);

        $this->capture()
            ->expectsOutputToContain('Unsupported historic video curation worksheet format')
            ->assertFailed();
    }

    private function draft(bool $skipProbe = false): PendingCommand
    {
        $parameters = [
            'root' => $this->rawRoot,
            '--worksheet' => $this->worksheetPath,
            '--batch-key' => 'video-draft-test',
            '--rule-version' => 'video-curation-v1',
        ];

        if ($skipProbe) {
            $parameters['--skip-probe'] = true;
        }

        return $this->artisan('historic-import:draft-video-curation', $parameters);
    }

    private function capture(): PendingCommand
    {
        return $this->artisan('historic-import:capture-video-curation', [
            'root' => $this->rawRoot,
            '--worksheet' => $this->worksheetPath,
            '--manifest' => $this->manifestPath,
        ]);
    }

    /** @return array<string, mixed> */
    private function readPrivate(string $relative): array
    {
        $contents = file_get_contents(storage_path('app/private/'.$relative));

        $this->assertIsString($contents);

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private function writePrivate(string $relative, array $payload): void
    {
        file_put_contents(
            storage_path('app/private/'.$relative),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    private function recording(string $date, string $service, string $filename): void
    {
        $directory = "{$this->rawRoot}/{$date}/{$service}";

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents("{$directory}/{$filename}", "recording {$date} {$service} {$filename}");
    }

    /**
     * Writes WebM through a pipe, which is what leaves the duration out of the
     * header — the same shape as the corpus's YouTube backups.
     */
    private function streamedWebm(string $path, int $seconds): void
    {
        $ffmpeg = (string) config('media-processing.ffmpeg.ffmpeg_path');
        $process = Process::fromShellCommandline(
            escapeshellarg($ffmpeg)." -v error -f lavfi -i testsrc=duration={$seconds}:size=160x120:rate=30 "
            .'-f webm pipe:1 > '.escapeshellarg($path),
        );
        $process->setTimeout(120)->run();

        $this->assertTrue($process->isSuccessful(), 'Could not build the WebM fixture: '.$process->getErrorOutput());
        $this->assertFileExists($path);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
