<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceSource;
use App\Enums\MediaType;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceSourceRecord;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\SourceAdapters\LivestreamSourceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

/**
 * Pair A (sermons 873/1045): one carol service on Sunday 2024-12-22 evening,
 * imported twice. The poorer copy sits on the real service; the better master was
 * filed a day late, so the import manufactured a Monday service and sermon for it.
 */
class CorrectHistoricRunIdentityCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private HistoricImportOperation $operation;

    private ChurchService $sunday;

    private MediaProcessingLog $poorer;

    private Sermon $sundaySermon;

    private ChurchService $monday;

    private MediaProcessingLog $better;

    private Sermon $mondaySermon;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('historic_quarantine');

        $this->operation = $this->createHistoricImportOperation();

        $this->sunday = $this->service('2024-12-22');
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $this->sunday->id,
            'source' => ChurchServiceSource::Email,
            'source_key' => 'email-2024-12-22-evening',
        ]);
        $this->poorer = $this->historicRun('2024-12-22', $this->sunday);
        $this->sundaySermon = $this->sermon($this->poorer, '2024-12-22', 'sunday');

        $this->monday = $this->service('2024-12-23');
        $this->better = $this->historicRun('2024-12-23', $this->monday);
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $this->monday->id,
            'source' => ChurchServiceSource::Livestream,
            'source_key' => $this->better->processing_id.'|v'.LivestreamSourceAdapter::FORMAT_VERSION,
        ]);
        $this->mondaySermon = $this->sermon($this->better, '2024-12-23', 'monday');
    }

    #[Test]
    public function it_writes_nothing_without_apply(): void
    {
        $this->artisan('historic-import:correct-run-identity', $this->args(apply: false))
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame('2024-12-23', $this->better->fresh()?->extracted_date?->toDateString());
        $this->assertFalse($this->poorer->fresh()?->isExcluded());
        $this->assertModelExists($this->monday);
        $this->assertModelExists($this->sundaySermon);
        $this->assertSame('2024-12-23', $this->mondaySermon->fresh()?->date->toDateString());
        Storage::disk('historic_quarantine')->assertExists('sermons/sunday/video.mp4');
    }

    #[Test]
    public function it_refuses_apply_without_confirmation(): void
    {
        $this->artisan('historic-import:correct-run-identity', [...$this->args(), '--yes' => false])->assertFailed();

        $this->assertSame('2024-12-23', $this->better->fresh()?->extracted_date?->toDateString());
    }

    #[Test]
    public function it_moves_the_better_run_onto_the_real_service_and_sets_the_poorer_copy_aside(): void
    {
        $note = 'Same carol service recorded once and imported twice; 1120 is the better master (8 Mbps vs 2.7).';

        $this->artisan('historic-import:correct-run-identity', $this->args(note: $note))->assertSuccessful();

        $better = $this->better->fresh();
        $this->assertSame('2024-12-22', $better?->extracted_date?->toDateString());
        $this->assertSame(SermonService::Evening, $better?->extracted_service);
        $this->assertSame($this->sunday->id, $better?->church_service_id);
        $this->assertNull($better?->superseded_at);

        $record = $better?->identityCorrectionRecord();
        $this->assertSame(['date' => '2024-12-23', 'service' => 'evening'], $record['from'] ?? null);
        $this->assertSame(['date' => '2024-12-22', 'service' => 'evening'], $record['to'] ?? null);
        $this->assertSame($note, $record['note'] ?? null);
        $this->assertSame($this->poorer->processing_id, $record['duplicate_processing_id'] ?? null);
        $this->assertSame($this->monday->id, $record['removed_service']['id'] ?? null);
        $this->assertSame($this->mondaySermon->id, $record['redated_sermons'][0]['id'] ?? null);
        $this->assertSame($this->sundaySermon->id, $record['withdrawn_sermons'][0]['id'] ?? null);

        $poorer = $this->poorer->fresh();
        $this->assertSame(MediaProcessingLog::EXCLUSION_REASON_DUPLICATE_RECORDING, $poorer?->exclusionReason());
        $this->assertSame($this->better->processing_id, $poorer?->exclusionEvidence()['duplicates_processing_id'] ?? null);
        $this->assertSame($this->better->id, $poorer?->superseded_by_processing_log_id);

        // The real occasion stays and the manufactured Monday service goes. The better
        // run's sermon moves to Sunday; the poorer copy's is withdrawn, bytes kept.
        $this->assertModelExists($this->sunday);
        $this->assertModelMissing($this->monday);
        $this->assertModelMissing($this->sundaySermon);
        $this->assertSame('2024-12-22', $this->mondaySermon->fresh()?->date->toDateString());
        $this->assertSame(SermonService::Evening, $this->mondaySermon->fresh()?->service);

        $prefix = "superseded/{$this->operation->operation_id}/{$this->sundaySermon->id}";
        Storage::disk('historic_quarantine')->assertMissing('sermons/sunday/video.mp4');
        Storage::disk('historic_quarantine')->assertExists("{$prefix}/video-video.mp4");
        Storage::disk('historic_quarantine')->assertExists("{$prefix}/identity-correction.json");
        Storage::disk('historic_quarantine')->assertExists('sermons/monday/video.mp4');
    }

    /**
     * A re-cut finds its sermon by run (`SubmitToProcessing`), so the better run
     * must still own exactly one row, now on the real service, and nothing else
     * may hold that service's sermon.
     */
    #[Test]
    public function the_corrected_run_still_owns_the_only_sermon_on_the_real_service(): void
    {
        $this->artisan('historic-import:correct-run-identity', $this->args())->assertSuccessful();

        $sermon = $this->better->fresh()?->sermon;

        $this->assertTrue($sermon?->is($this->mondaySermon));
        $this->assertSame(
            [$this->mondaySermon->id],
            Sermon::query()->whereDate('date', '2024-12-22')->where('service', 'evening')->pluck('id')->all(),
        );
    }

    #[Test]
    public function it_refuses_when_a_sermon_neither_run_owns_holds_the_real_service(): void
    {
        $other = Sermon::factory()->create(['date' => '2024-12-22', 'service' => SermonService::Evening->value]);

        $this->artisan('historic-import:correct-run-identity', $this->args())
            ->expectsOutputToContain("Sermon [{$other->id}]")
            ->assertFailed();

        $this->assertNothingChanged();
    }

    /**
     * Pair A as it really is: the poorer copy came in with an earlier operation,
     * the better master with a later one.
     */
    #[Test]
    public function the_poorer_copy_may_belong_to_an_earlier_operation(): void
    {
        $earlier = $this->createHistoricImportOperation();
        $this->poorer->forceFill(['historic_import_operation_id' => $earlier->id])->save();
        $this->poorer->writeProcessingMetadata(static function (array $metadata) use ($earlier): array {
            $metadata['historic_import']['operation_id'] = $earlier->operation_id;

            return $metadata;
        });

        $this->artisan('historic-import:correct-run-identity', $this->args())->assertSuccessful();

        $this->assertSame(MediaProcessingLog::EXCLUSION_REASON_DUPLICATE_RECORDING, $this->poorer->fresh()?->exclusionReason());
        $this->assertSame('2024-12-22', $this->better->fresh()?->extracted_date?->toDateString());
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        $this->artisan('historic-import:correct-run-identity', $this->args())->assertSuccessful();
        $first = $this->better->fresh()?->identityCorrectionRecord();

        $this->artisan('historic-import:correct-run-identity', $this->args())
            ->expectsOutputToContain('already corrected')
            ->assertSuccessful();

        $this->assertSame($first, $this->better->fresh()?->identityCorrectionRecord());
    }

    #[Test]
    public function it_refuses_a_duplicate_that_is_not_on_the_corrected_date(): void
    {
        $this->poorer->forceFill(['extracted_date' => '2024-12-15'])->save();

        $this->artisan('historic-import:correct-run-identity', $this->args())
            ->expectsOutputToContain('2024-12-15')
            ->assertFailed();

        $this->assertNothingChanged();
    }

    #[Test]
    public function it_refuses_when_the_poorer_copys_sermon_is_published(): void
    {
        $this->sundaySermon->forceFill(['publication_state' => SermonPublicationState::Published])->save();

        $this->artisan('historic-import:correct-run-identity', $this->args())
            ->expectsOutputToContain('published')
            ->assertFailed();

        $this->assertNothingChanged();
        Storage::disk('historic_quarantine')->assertExists('sermons/sunday/video.mp4');
    }

    #[Test]
    public function it_refuses_a_run_still_in_flight(): void
    {
        $this->better->forceFill(['status' => ProcessingStatus::Processing])->save();

        $this->artisan('historic-import:correct-run-identity', $this->args())
            ->expectsOutputToContain('in flight')
            ->assertFailed();

        $this->assertNothingChanged();
    }

    #[Test]
    public function it_refuses_a_run_that_is_already_its_own_identity(): void
    {
        $this->artisan('historic-import:correct-run-identity', [...$this->args(), '--date' => '2024-12-23'])
            ->assertFailed();

        $this->assertNothingChanged();
    }

    #[Test]
    public function it_refuses_a_correction_with_no_note(): void
    {
        $this->artisan('historic-import:correct-run-identity', $this->args(note: ''))->assertFailed();

        $this->assertNothingChanged();
    }

    #[Test]
    public function it_keeps_a_misdated_service_another_source_describes(): void
    {
        ChurchServiceSourceRecord::factory()->create([
            'church_service_id' => $this->monday->id,
            'source' => ChurchServiceSource::Email,
            'source_key' => 'email-2024-12-23-evening',
        ]);

        $this->artisan('historic-import:correct-run-identity', $this->args())->assertSuccessful();

        $this->assertModelExists($this->monday);
        $this->assertSame($this->sunday->id, $this->better->fresh()?->church_service_id);
        $this->assertNull($this->better->fresh()?->identityCorrectionRecord()['removed_service'] ?? null);
    }

    private function assertNothingChanged(): void
    {
        $this->assertSame('2024-12-23', $this->better->fresh()?->extracted_date?->toDateString());
        $this->assertNull($this->better->fresh()?->identityCorrectionRecord());
        $this->assertFalse($this->poorer->fresh()?->isExcluded());
        $this->assertModelExists($this->monday);
        $this->assertModelExists($this->sundaySermon);
        $this->assertSame('2024-12-23', $this->mondaySermon->fresh()?->date->toDateString());
    }

    /** @return array<string, mixed> */
    private function args(bool $apply = true, string $note = 'Pair A: the better master was filed a day late.'): array
    {
        return [
            '--operation' => $this->operation->operation_id,
            '--processing-id' => $this->better->processing_id,
            '--date' => '2024-12-22',
            '--service' => 'evening',
            '--duplicate' => $this->poorer->processing_id,
            '--note' => $note,
            '--apply' => $apply,
            '--yes' => $apply,
        ];
    }

    private function service(string $date): ChurchService
    {
        return ChurchService::factory()->create(['date' => $date, 'service' => SermonService::Evening->value]);
    }

    private function historicRun(string $date, ChurchService $service): MediaProcessingLog
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'historic_import_operation_id' => $this->operation->id,
            'processing_type' => MediaType::Livestream,
            'status' => ProcessingStatus::Failed,
            'current_step' => 'manual_review_required',
            'extracted_date' => $date,
            'extracted_service' => SermonService::Evening->value,
            'church_service_id' => $service->id,
            'processing_metadata' => [
                'historic_import' => [
                    'operation_id' => $this->operation->operation_id,
                    'manifest_item_key' => "{$date}-evening",
                ],
            ],
        ]);
        ServiceSection::factory()->create(['media_processing_log_id' => $run->id]);

        return $run;
    }

    private function sermon(MediaProcessingLog $run, string $date, string $folder): Sermon
    {
        $sermon = Sermon::factory()->fromLivestream()->create([
            'date' => $date,
            'service' => SermonService::Evening->value,
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
            'livestream_processing_id' => $run->processing_id,
            'video_file_path' => "sermons/{$folder}/video.mp4",
            'audio_file_path' => null,
            'transcript_file_path' => null,
            'thumbnail_file_path' => null,
        ]);
        Storage::disk('historic_quarantine')->put("sermons/{$folder}/video.mp4", "{$folder}-video-bytes");
        $run->forceFill(['sermon_id' => $sermon->id])->save();

        return $sermon;
    }
}
