<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

/**
 * The 11-02 round left ten main videos in quarantine a generation behind the outputs their runs
 * recorded (1025, 1055, 1133, 1199, 1214, 1286, 1299, 1305, 1325, 1349): each run read as completed
 * and nothing compared the file a sermon serves with the one its run made.
 */
class VerifyHistoricOutputGenerationsCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private const string RECORDED = 'the generation the run recorded';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_quarantine');
        Storage::fake('historic_staging');
    }

    #[Test]
    public function it_passes_when_the_served_video_is_the_one_its_run_recorded(): void
    {
        $this->historicRun(served: self::RECORDED);

        $this->artisan('historic-import:verify-output-generations')->assertSuccessful();

        $report = $this->jsonReport();
        $this->assertSame(1, $report['checked']);
        $this->assertSame(1, $report['current']);
        $this->assertSame([], $report['stale']);
    }

    #[Test]
    public function it_fails_when_quarantine_serves_an_older_generation(): void
    {
        $log = $this->historicRun(served: 'the generation before the round, longer');

        $this->artisan('historic-import:verify-output-generations')->assertFailed();

        $this->assertSame([$log->id], $this->jsonReport()['stale']);
    }

    /** Size alone cannot tell two cuts of one length apart; --hash can. */
    #[Test]
    public function the_hash_option_catches_a_same_size_generation(): void
    {
        $log = $this->historicRun(served: strrev(self::RECORDED));

        $this->artisan('historic-import:verify-output-generations')->assertSuccessful();
        $this->artisan('historic-import:verify-output-generations --hash')->assertFailed();

        $this->assertSame([$log->id], $this->jsonReport('--hash')['stale']);
    }

    #[Test]
    public function it_reports_a_served_video_that_is_not_there(): void
    {
        $log = $this->historicRun(served: null);

        $this->artisan('historic-import:verify-output-generations')->assertFailed();

        $this->assertSame([$log->id], $this->jsonReport()['missing']);
    }

    /** A superseded or unfinished run's recorded output is not what anyone serves. */
    #[Test]
    public function it_checks_only_completed_runs_still_in_force(): void
    {
        $this->historicRun(served: 'stale', attributes: ['superseded_at' => now()]);
        $this->historicRun(served: 'stale', attributes: ['status' => 'processing']);

        $this->artisan('historic-import:verify-output-generations')->assertSuccessful();

        $this->assertSame(0, $this->jsonReport()['checked']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function historicRun(?string $served, array $attributes = []): MediaProcessingLog
    {
        $sermon = Sermon::factory()->create([
            'asset_disk' => 'historic_quarantine',
            'video_file_path' => 'sermons/960/video.mp4',
        ]);
        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $this->createHistoricImportOperation()->id,
            'sermon_id' => $sermon->id,
            'processing_metadata' => ['media_outputs' => ['sermon' => [
                'disk' => 'historic_staging',
                'path' => 'sermons/960/video.mp4',
                'size' => strlen(self::RECORDED),
                'sha256' => hash('sha256', self::RECORDED),
            ]]],
            ...$attributes,
        ]);

        if ($served !== null) {
            Storage::disk('historic_quarantine')->put('sermons/960/video.mp4', $served);
        }

        return $log;
    }

    /** @return array<string, mixed> */
    private function jsonReport(string ...$options): array
    {
        Artisan::call(implode(' ', ['historic-import:verify-output-generations --json', ...$options]));
        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report);

        return $report;
    }
}
