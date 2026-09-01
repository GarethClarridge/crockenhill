<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\StoreSermonVideo;
use App\Models\HistoricImportNestedJob;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\HistoricMedia\HistoricPreM2VideoStorageRegistration;
use App\Services\Media\ExtractedMediaDurationProbe;
use App\Services\Processing\StorageAdapterHelper;
use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Format;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RegisterPreM2VideoStorageCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['media-processing.storage.historic_quarantine_disk' => 'historic_quarantine']);
        Storage::fake('historic_quarantine');
    }

    #[Test]
    public function it_is_dry_run_by_default(): void
    {
        [$operation, $log] = $this->preM2RunWithStoredVideo();

        $this->artisan('historic-import:register-pre-m2-video-storage', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
        ])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        self::assertSame(0, HistoricImportNestedJob::query()->count());
    }

    #[Test]
    public function it_requires_confirmation_before_apply(): void
    {
        [$operation, $log] = $this->preM2RunWithStoredVideo();

        $this->artisan('historic-import:register-pre-m2-video-storage', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
        ])
            ->expectsOutputToContain('--apply requires --yes')
            ->assertFailed();

        self::assertSame(0, HistoricImportNestedJob::query()->count());
    }

    #[Test]
    public function it_registers_completed_storage_for_a_verified_pre_m2_run(): void
    {
        [$operation, $log] = $this->preM2RunWithStoredVideo();

        $this->artisan('historic-import:register-pre-m2-video-storage', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('Registered 1 pre-M2 video storage record')
            ->assertSuccessful();

        $nestedJob = HistoricImportNestedJob::query()->sole();
        self::assertSame(StoreSermonVideo::class, $nestedJob->job_type);
        self::assertSame(StoreSermonVideo::nestedJobKey($log->processing_id), $nestedJob->job_key);
        self::assertSame('completed', $nestedJob->state);
        self::assertSame($operation->id, $nestedJob->historic_import_operation_id);
        self::assertSame($log->id, $nestedJob->media_processing_log_id);
    }

    #[Test]
    public function repeating_the_registration_is_a_no_op(): void
    {
        [$operation, $log] = $this->preM2RunWithStoredVideo();
        $arguments = [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ];

        $this->artisan('historic-import:register-pre-m2-video-storage', $arguments)->assertSuccessful();

        $this->artisan('historic-import:register-pre-m2-video-storage', $arguments)
            ->expectsOutputToContain('already registered: 1')
            ->assertSuccessful();

        self::assertSame(1, HistoricImportNestedJob::query()->count());
    }

    #[Test]
    public function it_refuses_a_run_created_after_registration_landed(): void
    {
        [$operation, $log] = $this->preM2RunWithStoredVideo();
        $log->forceFill([
            'created_at' => Carbon::parse(HistoricPreM2VideoStorageRegistration::REGISTRATION_LANDED_AT)->addMinute(),
        ])->save();

        $this->artisan('historic-import:register-pre-m2-video-storage', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('an absent registration there means storage never ran')
            ->assertFailed();

        self::assertSame(0, HistoricImportNestedJob::query()->count());
    }

    #[Test]
    public function it_refuses_a_run_whose_video_asset_is_absent(): void
    {
        [$operation, $log] = $this->preM2RunWithStoredVideo();
        Storage::disk('historic_quarantine')->delete('sermons/896/video.mp4');

        $this->artisan('historic-import:register-pre-m2-video-storage', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('video asset is missing from disk')
            ->assertFailed();

        self::assertSame(0, HistoricImportNestedJob::query()->count());
    }

    #[Test]
    public function it_refuses_a_video_asset_that_holds_no_media(): void
    {
        [$operation, $log] = $this->preM2RunWithStoredVideo();
        $this->bindProbe(0.0);

        $this->artisan('historic-import:register-pre-m2-video-storage', [
            '--operation' => $operation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('must have a positive duration')
            ->assertFailed();

        self::assertSame(0, HistoricImportNestedJob::query()->count());
    }

    #[Test]
    public function it_refuses_a_run_owned_by_another_operation(): void
    {
        [, $log] = $this->preM2RunWithStoredVideo();
        $otherOperation = $this->createHistoricImportOperation();

        $this->artisan('historic-import:register-pre-m2-video-storage', [
            '--operation' => $otherOperation->operation_id,
            '--processing-id' => [$log->processing_id],
            '--apply' => true,
            '--yes' => true,
        ])
            ->expectsOutputToContain('is not owned by operation')
            ->assertFailed();

        self::assertSame(0, HistoricImportNestedJob::query()->count());
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

    /** @return array{0: HistoricImportOperation, 1: MediaProcessingLog} */
    private function preM2RunWithStoredVideo(): array
    {
        $operation = $this->createHistoricImportOperation();
        Storage::disk('historic_quarantine')->put('sermons/896/video.mp4', 'stored-video');

        $log = MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $operation->id,
            'created_at' => Carbon::parse(HistoricPreM2VideoStorageRegistration::REGISTRATION_LANDED_AT)->subDay(),
            'processing_metadata' => [
                'historic_import' => [
                    'job_key' => 'pre-m2-registration-job',
                    'operation_id' => $operation->operation_id,
                ],
            ],
        ]);
        $sermon = Sermon::factory()->fromLivestream()->create([
            'livestream_processing_id' => $log->processing_id,
            'historic_import_operation_id' => $operation->id,
            'asset_disk' => 'historic_quarantine',
            'video_file_path' => 'sermons/896/video.mp4',
        ]);
        $log->forceFill(['sermon_id' => $sermon->id])->save();

        $this->bindProbe(1967.03);

        return [$operation, $log];
    }
}
