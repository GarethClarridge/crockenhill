<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use App\Services\HistoricMedia\ConcatenatedSourceRestage;
use App\Services\HistoricMedia\StagedSourceVerification;
use App\Services\Media\MediaCodecFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Real ffmpeg joins of synthetic parts. The rule under test is run 950's: a rebuilt
 * concatenation is proved by its parts' hashes and its timeline, never by the original
 * join's container bytes.
 */
class RestageConcatenatedHistoricSourceCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET = 'livestream/temp/concatenated.mkv';

    private string $archiveRoot;

    /** @var list<array{path: string, size: int, sha256: string}> */
    private array $parts;

    private float $joinedDuration;

    private string $codecFingerprint;

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_executable('/usr/bin/ffmpeg') || ! is_executable('/usr/bin/ffprobe')) {
            $this->markTestSkipped('ffmpeg and ffprobe are required to join real parts.');
        }

        Storage::fake('local');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.ffmpeg.ffmpeg_path', '/usr/bin/ffmpeg');
        Config::set('media-processing.ffmpeg.ffprobe_path', '/usr/bin/ffprobe');

        $this->archiveRoot = storage_path('framework/testing/concat-restage-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->archiveRoot.'/2024-05-12/Evening');

        // An apostrophe and a space, as archive names carry ("31 May_ Sermon part 2 [YouTube backup].mp4").
        $this->parts = [
            $this->part('2024-05-12/Evening/18-08.mkv', 2),
            $this->part("2024-05-12/Evening/it's part 2.mkv", 3),
        ];

        // The duration and streams the original join recorded, read from an independent join.
        $original = $this->archiveRoot.'/original-join.mkv';
        file_put_contents($this->archiveRoot.'/list.txt', implode('', array_map(
            fn (array $part): string => "file '".str_replace("'", "'\\''", $this->archiveRoot.'/'.$part['path'])."'\n",
            $this->parts,
        )));
        (new Process(['/usr/bin/ffmpeg', '-v', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', $this->archiveRoot.'/list.txt', '-c', 'copy', $original]))->mustRun();
        $this->joinedDuration = (float) trim((new Process(['/usr/bin/ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $original]))->mustRun()->getOutput());
        $this->codecFingerprint = (string) app(MediaCodecFingerprint::class)->for($original);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->archiveRoot);

        parent::tearDown();
    }

    #[Test]
    public function it_verifies_without_staging_by_default(): void
    {
        $run = $this->concatenatedRun();

        $this->artisan('historic-import:restage-concatenated-source', ['run' => $run->id, '--archive-root' => $this->archiveRoot])
            ->expectsOutputToContain('VERIFY ONLY')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing(self::TARGET);
        self::assertSame([], Storage::disk('local')->allFiles('livestream/temp'), 'the verification join is removed');
        self::assertNull($run->fresh()?->concatenatedSourceRestage());
    }

    #[Test]
    public function it_restages_a_join_the_rerun_route_then_accepts(): void
    {
        $run = $this->concatenatedRun();
        self::assertSame(
            'concatenated source has not been rebuilt through historic-import:restage-concatenated-source',
            app(StagedSourceVerification::class)->refusal($run),
        );

        $this->artisan('historic-import:restage-concatenated-source', ['run' => $run->id, '--archive-root' => $this->archiveRoot, '--execute' => true])
            ->expectsOutputToContain('Restaged and stamped.')
            ->assertSuccessful();

        Storage::disk('local')->assertExists(self::TARGET);
        $stamp = $run->fresh()?->concatenatedSourceRestage();
        self::assertSame(hash_file('sha256', Storage::disk('local')->path(self::TARGET)), $stamp['sha256'] ?? null);
        self::assertSame(2, $stamp['parts'] ?? null);
        self::assertSame(Storage::disk('local')->size(self::TARGET), $stamp['size'] ?? null);

        // The original join's hash stays as provenance; the staged check reads the stamp.
        self::assertSame(str_repeat('e', 64), $run->fresh()?->file_hash);
        self::assertNull(app(StagedSourceVerification::class)->refusal($run->fresh()));
    }

    #[Test]
    public function it_resumes_without_rejoining_a_run_already_restaged(): void
    {
        $run = $this->concatenatedRun();
        $this->artisan('historic-import:restage-concatenated-source', ['run' => $run->id, '--archive-root' => $this->archiveRoot, '--execute' => true])
            ->assertSuccessful();

        // A part going missing afterwards does not matter: the stamp already proves the staged file.
        unlink($this->archiveRoot.'/'.$this->parts[0]['path']);

        $this->artisan('historic-import:restage-concatenated-source', ['run' => $run->id, '--archive-root' => $this->archiveRoot, '--execute' => true])
            ->expectsOutputToContain('Already restaged')
            ->assertSuccessful();
    }

    #[Test]
    public function it_refuses_a_part_that_is_not_the_file_the_manifest_approved(): void
    {
        $run = $this->concatenatedRun(parts: [
            $this->parts[0],
            [...$this->parts[1], 'sha256' => hash('sha256', 'another recording')],
        ]);

        $this->artisan('historic-import:restage-concatenated-source', ['run' => $run->id, '--archive-root' => $this->archiveRoot, '--execute' => true])
            ->expectsOutputToContain("Part 2 (2024-05-12/Evening/it's part 2.mkv) does not hash")
            ->assertFailed();

        Storage::disk('local')->assertMissing(self::TARGET);
    }

    #[Test]
    public function it_refuses_a_join_whose_timeline_is_not_the_runs(): void
    {
        // Verified parts, but not the timeline the run was processed on: a manifest missing a
        // part reads exactly like this.
        $run = $this->concatenatedRun(duration: $this->joinedDuration + 1.0);

        $this->artisan('historic-import:restage-concatenated-source', ['run' => $run->id, '--archive-root' => $this->archiveRoot, '--execute' => true])
            ->expectsOutputToContain('not the timeline this run was processed on')
            ->assertFailed();

        Storage::disk('local')->assertMissing(self::TARGET);
        self::assertSame([], Storage::disk('local')->allFiles('livestream/temp'));
        self::assertNull($run->fresh()?->concatenatedSourceRestage());
    }

    #[Test]
    public function it_refuses_a_join_whose_streams_are_not_the_runs(): void
    {
        $run = $this->concatenatedRun(codec: 'h264:aac:1920x1080:30/1:48000');

        $this->artisan('historic-import:restage-concatenated-source', ['run' => $run->id, '--archive-root' => $this->archiveRoot, '--execute' => true])
            ->expectsOutputToContain('the run recorded h264:aac:1920x1080:30/1:48000')
            ->assertFailed();

        Storage::disk('local')->assertMissing(self::TARGET);
    }

    #[Test]
    public function it_stamps_a_staged_file_that_is_exactly_the_join(): void
    {
        // Runs 973 and 1014: the original join is still staged but its hash was never recorded.
        $run = $this->concatenatedRun();
        Storage::disk('local')->put(self::TARGET, (string) file_get_contents($this->archiveRoot.'/original-join.mkv'));

        $this->artisan('historic-import:restage-concatenated-source', ['run' => $run->id, '--archive-root' => $this->archiveRoot, '--execute' => true])
            ->expectsOutputToContain('The staged file is exactly this join; stamped.')
            ->assertSuccessful();

        self::assertNull(app(StagedSourceVerification::class)->refusal($run->fresh()));
    }

    #[Test]
    public function it_never_overwrites_a_staged_file_it_cannot_vouch_for(): void
    {
        $run = $this->concatenatedRun();
        Storage::disk('local')->put(self::TARGET, 'a file of unknown origin');

        $this->artisan('historic-import:restage-concatenated-source', ['run' => $run->id, '--archive-root' => $this->archiveRoot, '--execute' => true])
            ->expectsOutputToContain('A different file is already staged')
            ->assertFailed();

        self::assertSame('a file of unknown origin', Storage::disk('local')->get(self::TARGET));
        self::assertNull($run->fresh()?->concatenatedSourceRestage());
    }

    #[Test]
    public function it_refuses_a_single_part_run(): void
    {
        $run = $this->concatenatedRun(concatenation: 'none');

        $this->artisan('historic-import:restage-concatenated-source', ['run' => $run->id, '--archive-root' => $this->archiveRoot])
            ->expectsOutputToContain('Only a lossless concatenation')
            ->assertFailed();
    }

    /**
     * @param  list<array{path: string, size: int, sha256: string}>|null  $parts
     */
    private function concatenatedRun(?array $parts = null, ?float $duration = null, ?string $codec = null, string $concatenation = 'lossless'): MediaProcessingLog
    {
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'source_file_path' => self::TARGET,
            'file_hash' => str_repeat('e', 64),
            'duration' => round($duration ?? $this->joinedDuration, 3),
            'processing_metadata' => [
                'historic_import' => [
                    'concatenation' => $concatenation,
                    'codec_fingerprint' => $codec ?? $this->codecFingerprint,
                    'sources' => $parts ?? $this->parts,
                ],
            ],
        ]);

        self::assertSame(ConcatenatedSourceRestage::STAMP_KEY, 'concatenated_source_restage');

        return $run;
    }

    /** @return array{path: string, size: int, sha256: string} */
    private function part(string $relativePath, int $seconds): array
    {
        $path = $this->archiveRoot.'/'.$relativePath;

        (new Process([
            '/usr/bin/ffmpeg', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', "testsrc2=size=160x120:rate=25:duration={$seconds}",
            '-f', 'lavfi', '-i', "sine=frequency=440:sample_rate=48000:duration={$seconds}",
            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '64k',
            $path,
        ]))->setTimeout(60)->mustRun();

        return ['path' => $relativePath, 'size' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)];
    }
}
