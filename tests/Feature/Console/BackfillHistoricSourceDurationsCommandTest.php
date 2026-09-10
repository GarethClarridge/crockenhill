<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Processing\StorageAdapterHelper;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackfillHistoricSourceDurationsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $archiveRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archiveRoot = sys_get_temp_dir().'/archive-'.uniqid();
        mkdir($this->archiveRoot.'/2020-09-13/Morning', 0755, true);
        $this->bindProbe(3612.5);
    }

    protected function tearDown(): void
    {
        $this->deleteArchiveRoot($this->archiveRoot);

        parent::tearDown();
    }

    #[Test]
    public function it_refuses_to_report_an_unmounted_archive_as_an_empty_corpus(): void
    {
        // A detached drive answers "absent" for every file, which is exactly what
        // a reaped archive looks like. The root is checked before anything else.
        $this->runWithNoDuration('2020-09-13/Morning/service.webm', 1024);

        $this->artisan('historic-import:backfill-source-durations', [
            '--archive-root' => $this->archiveRoot.'/not-mounted',
        ])
            ->expectsOutputToContain('Is the drive mounted?')
            ->assertFailed();
    }

    #[Test]
    public function it_measures_without_writing_anything_by_default(): void
    {
        $run = $this->runWithNoDuration('2020-09-13/Morning/service.webm', 1024);

        $this->artisan('historic-import:backfill-source-durations', ['--archive-root' => $this->archiveRoot])
            ->expectsOutputToContain('3612.50')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertNull($run->fresh()->duration);
    }

    #[Test]
    public function it_records_the_measured_duration_when_applied(): void
    {
        $run = $this->runWithNoDuration('2020-09-13/Morning/service.webm', 1024);

        $this->artisan('historic-import:backfill-source-durations', [
            '--archive-root' => $this->archiveRoot,
            '--execute' => true,
        ])
            ->expectsOutputToContain('Recorded 1 duration(s)')
            ->assertSuccessful();

        $this->assertSame(3612.5, (float) $run->fresh()->duration);
    }

    #[Test]
    public function it_refuses_a_copy_whose_size_the_manifest_did_not_approve(): void
    {
        // The reason this matters: the duration it would bank is then used to
        // clamp sections that are timed against a different capture entirely.
        $run = $this->runWithNoDuration('2020-09-13/Morning/service.webm', 1024, approvedSize: 999_999);

        $this->artisan('historic-import:backfill-source-durations', [
            '--archive-root' => $this->archiveRoot,
            '--execute' => true,
        ])
            ->expectsOutputToContain('it is a different recording')
            ->assertSuccessful();

        $this->assertNull($run->fresh()->duration);
    }

    #[Test]
    public function it_refuses_a_copy_that_does_not_hash_to_the_recorded_source(): void
    {
        $run = $this->runWithNoDuration('2020-09-13/Morning/service.webm', 1024, sha256: str_repeat('a', 64));

        $this->artisan('historic-import:backfill-source-durations', [
            '--archive-root' => $this->archiveRoot,
            '--verify-hash' => true,
            '--execute' => true,
        ])
            ->expectsOutputToContain('does not hash to the recording')
            ->assertSuccessful();

        $this->assertNull($run->fresh()->duration);
    }

    #[Test]
    public function it_leaves_a_run_that_already_records_a_duration_alone(): void
    {
        $run = $this->runWithNoDuration('2020-09-13/Morning/service.webm', 1024);
        $run->forceFill(['duration' => 1200.0])->save();

        $this->artisan('historic-import:backfill-source-durations', [
            '--archive-root' => $this->archiveRoot,
            '--execute' => true,
        ])
            ->expectsOutputToContain('No runs matched this selection')
            ->assertSuccessful();

        $this->assertSame(1200.0, (float) $run->fresh()->duration);
    }

    #[Test]
    public function it_reports_a_concatenated_import_as_unresolved_rather_than_summing_its_parts(): void
    {
        $run = $this->runWithNoDuration('2020-09-13/Morning/service.webm', 1024);
        $metadata = $run->processing_metadata->toArray();
        $metadata['historic_import']['concatenation'] = 'ffmpeg';
        $run->forceFill(['processing_metadata' => $metadata])->save();

        $this->artisan('historic-import:backfill-source-durations', [
            '--archive-root' => $this->archiveRoot,
            '--execute' => true,
        ])
            ->expectsOutputToContain('no single-part archive source is recorded')
            ->assertSuccessful();

        $this->assertNull($run->fresh()->duration);
    }

    private function runWithNoDuration(
        string $relativePath,
        int $bytes,
        ?int $approvedSize = null,
        ?string $sha256 = null,
    ): MediaProcessingLog {
        $absolute = $this->archiveRoot.'/'.$relativePath;
        file_put_contents($absolute, str_repeat('x', $bytes));

        $run = MediaProcessingLog::factory()->livestream()->create([
            'duration' => null,
            'processing_metadata' => [
                'historic_import' => [
                    'concatenation' => 'none',
                    'sources' => [[
                        'path' => $relativePath,
                        'size' => $approvedSize ?? $bytes,
                        'sha256' => $sha256 ?? hash_file('sha256', $absolute),
                    ]],
                ],
            ],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 100.0,
            'end_time' => 2000.0,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        return $run;
    }

    private function bindProbe(float $duration): void
    {
        $format = $this->createStub(Format::class);
        $format->method('get')->willReturn($duration);
        $ffprobe = $this->createStub(FFProbe::class);
        $ffprobe->method('format')->willReturn($format);

        $this->app->bind(
            ExtractedMediaDurationProbe::class,
            fn (): ExtractedMediaDurationProbe => new ExtractedMediaDurationProbe(
                app(StorageAdapterHelper::class),
                $ffprobe,
            ),
        );
    }

    private function deleteArchiveRoot(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->deleteArchiveRoot($child) : unlink($child);
        }

        rmdir($path);
    }
}
