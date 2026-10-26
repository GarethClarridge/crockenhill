<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\ChurchServiceTranscript;
use App\Data\HistoricStagingContext;
use App\Enums\ProcessingStatus;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\CueSafeExtractionPlan;
use App\Services\HistoricMedia\HistoricFailedTranscriptCandidates;
use App\Services\HistoricMedia\HistoricFailedTranscriptRepair;
use App\Services\HistoricMedia\HistoricStagingContextRegistry;
use App\Services\Media\Audio\LocalWhisperServiceTranscriptionService;
use App\Services\Media\Audio\ServiceArtifactStorage;
use App\Services\Media\Audio\ServiceAudioWindowExtractor;
use App\Services\Media\Audio\ServiceTranscriptReader;
use App\Services\Processing\StorageAdapterHelper;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class RepairFailedHistoricTranscriptsCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use DatabaseTransactions;

    private string $directory;

    private string $donor;

    private bool $plannerMissing = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['media-processing.storage.transcript_disk' => 'local', 'media-processing.storage.service_artifact_disk' => 'local']);
        $this->directory = storage_path('app/private/failed-transcript-test-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->directory);
        $this->donor = $this->directory.'/donor.json';
        $contexts = Mockery::mock(HistoricStagingContextRegistry::class);
        $contexts->shouldReceive('within')->andReturnUsing(static fn (HistoricStagingContext $context, Closure $callback): mixed => $callback());
        $this->app->instance(HistoricStagingContextRegistry::class, $contexts);
        $planner = Mockery::mock(CueSafeExtractionPlan::class);
        $planner->shouldReceive('audioTimeline')->andReturnNull();
        $planner->shouldReceive('forSection')->andReturnUsing(function (ServiceSection $section, ChurchServiceTranscript $transcript): array {
            if ($this->plannerMissing) {
                throw new RuntimeException('Banked edge words missing');
            }

            return ['segments' => [['start_time' => $transcript->cues[0]['start'], 'end_time' => (float) $section->end_time]],
                'cue_edge_widening' => [], 'audio_fade_out' => null];
        });
        $this->app->instance(CueSafeExtractionPlan::class, $planner);
        Http::preventStrayRequests();
        Http::fake();
        Bus::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function inspection_and_preparation_are_read_only_and_installation_is_reversible(): void
    {
        $run = $this->historicRun();
        $before = $run->fresh()->getAttributes();
        $section = $run->serviceSections()->firstOrFail()->getAttributes();
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run, $this->donor);
        $adoption = $repair->prepare($proposal, $this->review($proposal));

        self::assertSame($before, $run->fresh()->getAttributes());
        self::assertTrue($adoption['impact_complete']);
        self::assertTrue($adoption['impacts'][0]['changed']);
        Storage::disk('local')->assertExists('service-transcripts/before.json');
        self::assertCount(1, Storage::disk('local')->allFiles());
        self::assertSame('applied', $repair->apply($adoption));
        self::assertSame('already_applied', $repair->apply($adoption));
        self::assertSame('already_applied', $repair->previewAdoption($adoption)['outcome']);
        $changed = $run->fresh();
        $output = app(ServiceTranscriptReader::class)->read($changed);
        self::assertSame(351.62, $output->cues[0]['start']);
        self::assertSame(3, count($output->unobservableWindows));
        self::assertSame(HistoricFailedTranscriptCandidates::REASON, $output->unobservableWindows[1]['reason']);
        self::assertSame($section, $run->serviceSections()->firstOrFail()->getAttributes());
        self::assertSame(['replayed_at' => 'old'], $changed->processing_metadata->toArray()['transcript_recovery_replay']);
        $files = Storage::disk('local')->allFiles();
        self::assertCount(3, $files);
        self::assertSame('would_rollback', $repair->rollback($changed, $adoption['sha256'], false));
        self::assertSame('rolled_back', $repair->rollback($changed, $adoption['sha256'], true));
        $restored = $run->fresh()->processing_metadata->toArray();
        foreach (['service_transcript_path', 'service_transcript_unobservable_windows', 'service_transcript_suspect_blocks', 'service_transcript_content'] as $field) {
            self::assertSame($run->processing_metadata->toArray()[$field] ?? 'absent', $restored[$field] ?? 'absent');
        }
        self::assertSame($this->current()->toArray(), app(ServiceTranscriptReader::class)->read($run->fresh())->toArray());
        self::assertSame($files, Storage::disk('local')->allFiles());
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function the_command_requires_exact_runs_and_creates_private_reports_once(): void
    {
        $run = $this->historicRun();
        $output = $this->directory.'/proposal.json';
        $args = ['action' => 'inspect', '--run' => [(string) $run->id], '--donor' => [$run->id.'='.$this->donor], '--output' => $output];
        $this->artisan('historic-import:repair-failed-transcripts', ['action' => 'inspect', '--output' => $output])->assertFailed();
        $this->artisan('historic-import:repair-failed-transcripts', $args)->assertSuccessful();
        self::assertSame('0600', substr(sprintf('%o', fileperms($output)), -4));
        $bundle = app(HistoricFailedTranscriptRepair::class)->readJson($output);
        $proposal = $bundle['entries'][$run->id];
        self::assertCount(1, $proposal['report']['candidates']);
        $this->artisan('historic-import:repair-failed-transcripts', $args)->assertFailed();
        $reviews = $this->directory.'/reviews.json';
        file_put_contents($reviews, CanonicalJson::encode(['runs' => [$run->id => $this->review($proposal)]]));
        $prepared = $this->directory.'/adoption.json';
        $this->artisan('historic-import:repair-failed-transcripts', ['action' => 'prepare', '--run' => [(string) $run->id],
            '--input' => $output, '--reviews' => $reviews, '--output' => $prepared])->assertSuccessful();
        $this->artisan('historic-import:repair-failed-transcripts', ['action' => 'apply', '--run' => [(string) $run->id], '--input' => $prepared])->assertSuccessful();
        self::assertSame('service-transcripts/before.json', $run->fresh()->serviceTranscriptPath());
        $this->artisan('historic-import:repair-failed-transcripts', ['action' => 'apply', '--run' => [(string) $run->id], '--input' => $prepared, '--execute' => true])->assertSuccessful();
        self::assertNotSame('service-transcripts/before.json', $run->fresh()->serviceTranscriptPath());
    }

    #[Test]
    public function missing_donors_preserve_the_failed_window_census_and_other_runs_can_progress(): void
    {
        $run = $this->historicRun();
        $other = $this->historicRun();
        $output = $this->directory.'/partial.json';
        $this->artisan('historic-import:repair-failed-transcripts', ['action' => 'inspect',
            '--run' => [(string) $run->id, (string) $other->id], '--donor' => [$other->id.'='.$this->donor], '--output' => $output])->assertFailed();
        $bundle = app(HistoricFailedTranscriptRepair::class)->readJson($output);
        self::assertCount(1, $bundle['entries'][$run->id]['failed_windows']);
        self::assertSame('proposal', $bundle['entries'][$other->id]['kind']);
    }

    #[Test]
    public function local_word_preparation_is_explicit_and_keeps_transcript_and_sections_unchanged(): void
    {
        config(['media-processing.service_structure.transcription_service' => 'local']);
        $run = $this->historicRun();
        $section = $run->serviceSections()->firstOrFail();
        $section->update(['section_type' => ServiceSectionType::Song, 'start_time' => 300, 'end_time' => 354.9, 'duration' => 54.9]);
        app(CueSafeExtractionPlan::class)->shouldReceive('songEndSpeechEdges')->andReturnUsing(
            static fn (MediaProcessingLog $log, array $spans, ChurchServiceTranscript $text): array => [$text->cues[0]['start']],
        );
        $run->writeProcessingMetadata(static fn (array $m): array => [...$m,
            'service_artifacts' => [['kind' => 'audio', 'disk' => 'local', 'path' => 'service-audio/test.mp3']]]);
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run->fresh(), $this->donor);
        $before = $run->fresh()->getAttributes();
        $sectionBefore = $section->fresh()->getAttributes();
        $probe = $repair->prepareWords($proposal, [$section->id], false);
        self::assertTrue($probe['windows'][0]['missing']);
        self::assertSame(350.62, $probe['windows'][0]['start']);
        self::assertSame(355.0, $probe['windows'][0]['end']);
        self::assertSame($before, $run->fresh()->getAttributes());
        Http::assertNothingSent();

        $extractor = Mockery::mock(ServiceAudioWindowExtractor::class);
        $extractor->shouldReceive('extract')->once()->with('/audio.mp3', 350.62, 355.0, $run->processing_id)->andReturn('/window.mp3');
        $extractor->shouldReceive('delete')->once()->with('/window.mp3');
        $this->app->instance(ServiceAudioWindowExtractor::class, $extractor);
        $storage = Mockery::mock(StorageAdapterHelper::class);
        $storage->shouldReceive('downloadToTemp')->once()->andReturn('/audio.mp3');
        $storage->shouldReceive('isS3CompatibleDisk')->once()->andReturn(false);
        $this->app->instance(StorageAdapterHelper::class, $storage);
        $whisper = Mockery::mock(LocalWhisperServiceTranscriptionService::class);
        $whisper->shouldReceive('transcribeEdgeWindow')->once()->andReturn([['start' => 1.0, 'end' => 1.4, 'word' => "Let's"]]);
        $this->app->instance(LocalWhisperServiceTranscriptionService::class, $whisper);
        self::assertSame(1, $repair->prepareWords($proposal, [$section->id], true)['summary']['decoded']);
        self::assertSame(0, $repair->prepareWords($proposal, [$section->id], true)['summary']['decoded']);
        self::assertSame($sectionBefore, $section->fresh()->getAttributes());
        self::assertSame($before['status'], $run->fresh()->getAttributes()['status']);
        self::assertSame('service-transcripts/before.json', $run->fresh()->serviceTranscriptPath());
        Bus::assertNothingDispatched();
    }

    #[Test]
    public function repair_words_refuses_nonlocal_providers_and_implicit_sections(): void
    {
        $run = $this->historicRun();
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run, $this->donor);
        $bundle = $this->directory.'/words-input.json';
        file_put_contents($bundle, CanonicalJson::encode(['schema' => HistoricFailedTranscriptRepair::SCHEMA, 'entries' => [$run->id => $proposal]]));
        $this->artisan('historic-import:repair-failed-transcripts', ['action' => 'words', '--run' => [(string) $run->id], '--input' => $bundle])->assertFailed();
        foreach (['mock', 'openai'] as $provider) {
            config(['media-processing.service_structure.transcription_service' => $provider]);
            $this->artisan('historic-import:repair-failed-transcripts', ['action' => 'words', '--run' => [(string) $run->id],
                '--section' => [(string) $run->serviceSections()->firstOrFail()->id], '--input' => $bundle, '--execute' => true])->assertFailed();
        }
        Http::assertNothingSent();
        self::assertCount(1, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function source_reviews_survive_section_changes_but_prepared_adoptions_do_not(): void
    {
        $run = $this->historicRun();
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run, $this->donor);
        $review = $this->review($proposal);
        $adoption = $repair->prepare($proposal, $review);
        $run->serviceSections()->firstOrFail()->update(['end_time' => 425, 'duration' => 75]);

        $refreshed = $repair->prepare($proposal, $review);
        self::assertSame($adoption['accepted'], $refreshed['accepted']);
        self::assertNotSame($adoption['proposal']['sections_sha256'], $refreshed['proposal']['sections_sha256']);
        $this->expectExceptionMessage('Adoption changed since preparation');
        $repair->apply($adoption);
    }

    #[Test]
    public function listening_membership_excludes_windows_without_an_edge(): void
    {
        $run = $this->historicRun();
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run, $this->donor);
        self::assertSame([$proposal['report']['candidates'][0]['id']], $proposal['listening_candidate_ids']);
        $run->serviceSections()->firstOrFail()->update(['start_time' => 410, 'duration' => 10]);
        self::assertSame([], $repair->inspect($run->fresh(), $this->donor)['listening_candidate_ids']);
    }

    #[Test]
    public function stale_current_text_donor_and_review_hashes_are_refused(): void
    {
        $run = $this->historicRun();
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run, $this->donor);
        $review = $this->review($proposal);
        $review['decisions'][0]['source_sha256'] = str_repeat('0', 64);
        try {
            $repair->prepare($proposal, $review);
            self::fail('Wrong review hash accepted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('source coverage', $e->getMessage());
        }
        $adoption = $repair->prepare($proposal, $this->review($proposal));
        file_put_contents($this->donor, "\n", FILE_APPEND);
        try {
            $repair->apply($adoption);
            self::fail('Changed donor accepted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('source coverage', $e->getMessage());
        }
        Storage::disk('local')->put('service-transcripts/before.json', CanonicalJson::encode(ChurchServiceTranscript::fromCues([], 500, 'local_whisper')->toArray()));
        $this->expectException(RuntimeException::class);
        $repair->apply($adoption);
    }

    #[Test]
    public function unknown_quarantined_and_unheard_text_cannot_be_adopted(): void
    {
        $run = $this->historicRun();
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run, $this->donor);
        $review = $this->review($proposal);
        $review['decisions'][0]['audio_end'] = 352;
        $this->expectExceptionMessage('Acceptance lacks full source coverage');
        $repair->prepare($proposal, $review);
    }

    #[Test]
    public function changed_september_text_needs_reconciliation_and_unresolved_plans_block_application(): void
    {
        $run = $this->historicRun();
        $donor = json_decode(file_get_contents($this->donor), true);
        $donor['stored_transcript']['sha256'] = str_repeat('e', 64);
        file_put_contents($this->donor, CanonicalJson::encode($donor));
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run, $this->donor);
        $review = $this->review($proposal);
        try {
            $repair->prepare($proposal, $review);
            self::fail('Missing reconciliation accepted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('reconciliation', $e->getMessage());
        }
        $review['reconciliation'] = 'The current 271–403 window is still empty and failed.';
        $this->plannerMissing = true;
        $adoption = $repair->prepare($proposal, $review);
        self::assertFalse($adoption['impact_complete']);
        $this->expectExceptionMessage('unresolved planner evidence');
        $repair->apply($adoption);
    }

    #[Test]
    public function rollback_refuses_later_metadata_changes(): void
    {
        $run = $this->historicRun();
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run, $this->donor);
        $adoption = $repair->prepare($proposal, $this->review($proposal));
        $repair->apply($adoption);
        $run->refresh();
        $run->writeProcessingMetadata(static function (array $m): array {
            $m['service_transcript_suspect_blocks'] = [['later' => true]];

            return $m;
        });
        $this->expectExceptionMessage('later transcript or metadata change');
        $repair->rollback($run->fresh(), $adoption['sha256'], true);
    }

    #[Test]
    public function an_orphan_output_is_reused_and_a_conflicting_output_is_never_overwritten(): void
    {
        $run = $this->historicRun();
        $artifacts = app(ServiceArtifactStorage::class);
        $path = $artifacts->putJsonOnce($run->processing_id, 'test-repair', ['proof' => 'original']);
        self::assertSame($path, $artifacts->putJsonOnce($run->processing_id, 'test-repair', ['proof' => 'original']));
        try {
            $artifacts->putJsonOnce($run->processing_id, 'test-repair', ['proof' => 'changed']);
            self::fail('Evidence overwritten');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Conflicting immutable artifact', $e->getMessage());
        }
        self::assertSame(CanonicalJson::encode(['proof' => 'original']), Storage::disk('local')->get($path));
    }

    #[Test]
    public function interruption_after_artifact_writes_rolls_back_database_state_and_resumes(): void
    {
        $run = $this->historicRun();
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run, $this->donor);
        $adoption = $repair->prepare($proposal, $this->review($proposal));
        $failOnce = true;
        MediaProcessingLog::saving(static function (MediaProcessingLog $saving) use (&$failOnce, $run): void {
            if ($saving->id === $run->id && $failOnce && isset($saving->processing_metadata->toArray()[HistoricFailedTranscriptRepair::RECEIPTS])) {
                $failOnce = false;
                throw new RuntimeException('Simulated interruption before receipt commit');
            }
        });
        try {
            $repair->apply($adoption);
            self::fail('Interruption was not exercised');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Simulated interruption', $e->getMessage());
        }
        self::assertSame('service-transcripts/before.json', $run->fresh()->serviceTranscriptPath());
        self::assertSame([], ServiceArtifactStorage::recordedFor($run->fresh()));
        self::assertCount(3, Storage::disk('local')->allFiles());
        $this->travel(5)->minutes();
        self::assertSame('applied', $repair->apply($adoption));
        self::assertSame('already_applied', $repair->apply($adoption));
        self::assertCount(3, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function altered_adoption_artifacts_fail_dry_run_as_well_as_execution(): void
    {
        $run = $this->historicRun();
        $repair = app(HistoricFailedTranscriptRepair::class);
        $proposal = $repair->inspect($run, $this->donor);
        $adoption = $repair->prepare($proposal, $this->review($proposal));
        $adoption['output']['cues'][0]['text'] = 'Unheard replacement';
        $input = $this->directory.'/altered.json';
        file_put_contents($input, CanonicalJson::encode(['schema' => HistoricFailedTranscriptRepair::SCHEMA, 'entries' => [$run->id => $adoption]]));
        $this->artisan('historic-import:repair-failed-transcripts', ['action' => 'apply', '--run' => [(string) $run->id], '--input' => $input])->assertFailed();
        $this->artisan('historic-import:repair-failed-transcripts', ['action' => 'apply', '--run' => [(string) $run->id], '--input' => $input, '--execute' => true])->assertFailed();
        self::assertSame('service-transcripts/before.json', $run->fresh()->serviceTranscriptPath());
    }

    #[Test]
    public function adverse_listening_and_alternative_decodes_are_preserved_and_quarantined(): void
    {
        $run = $this->historicRun();
        $repair = app(HistoricFailedTranscriptRepair::class);
        $listening = $this->directory.'/listening.json';
        file_put_contents($listening, CanonicalJson::encode(['verdicts' => [['run' => $run->id, 'start' => 330, 'verdict' => 'new_wrong']]]));
        $alternative = $repair->readJson($this->donor);
        $alternative['new_transcript']['cues'][0]['text'] = 'A different phrase';
        $path = $this->directory.'/alternative.json';
        file_put_contents($path, CanonicalJson::encode($alternative));
        $proposal = $repair->inspect($run, $this->donor, [$listening], [$path]);
        self::assertContains('alternative_disagreement', $proposal['report']['candidates'][0]['flags']);
        self::assertContains('adverse_listening_verdict', $proposal['report']['candidates'][0]['flags']);
        self::assertCount(1, $proposal['alternatives']);
        $this->expectExceptionMessage('quarantined text');
        $repair->prepare($proposal, $this->review($proposal));
    }

    private function current(): ChurchServiceTranscript
    {
        return ChurchServiceTranscript::fromCues([['start' => 403.1, 'end' => 408.0, 'text' => 'The service continues.']], 500, 'local_whisper',
            [['start' => 271.0, 'end' => 403.0, 'reason' => 'retranscription_failed']]);
    }

    private function historicRun(): MediaProcessingLog
    {
        $operation = $this->createHistoricImportOperation();
        $context = new HistoricStagingContext(manifestHash: str_repeat('a', 64), planHash: str_repeat('b', 64), stagingDisk: 'historic_staging',
            batchRoot: 'historic-batches/test', storageIdentity: ['driver' => 'local', 'bucket' => null, 'root_fingerprint' => str_repeat('c', 64), 'prefix_fingerprint' => str_repeat('d', 64)]);
        $run = MediaProcessingLog::factory()->livestream()->create(['historic_import_operation_id' => $operation->id,
            'file_hash' => str_repeat('a', 64), 'status' => ProcessingStatus::Completed, 'duration' => 500,
            'processing_metadata' => ['historic_import' => ['operation_id' => $operation->operation_id, 'staging_context' => $context->toArray()],
                'service_transcript_path' => 'service-transcripts/before.json',
                'service_transcript_unobservable_windows' => $this->current()->unobservableWindows,
                'transcript_recovery_replay' => ['replayed_at' => 'old']]]);
        ServiceSection::factory()->create(['media_processing_log_id' => $run->id, 'section_type' => ServiceSectionType::Prayer, 'start_time' => 350, 'end_time' => 420]);
        Storage::disk('local')->put('service-transcripts/before.json', CanonicalJson::encode($this->current()->toArray()));
        file_put_contents($this->donor, CanonicalJson::encode(['schema' => 'h10b-redecode-v1', 'run_id' => $run->id, 'processing_id' => $run->processing_id,
            'source' => ['sha256' => $run->stagedSourceFileHash()], 'stored_transcript' => ['sha256' => CanonicalJson::hash($this->current()->toArray())],
            'decode' => ['request' => ['max_context' => '0']], 'new_transcript' => ChurchServiceTranscript::fromCues([
                ['start' => 351.62, 'end' => 354.0, 'text' => "Let's join together in prayer."],
            ], 510, 'local_whisper')->toArray()]));

        return $run->fresh();
    }

    /** @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private function review(array $proposal): array
    {
        return ['proposal_sha256' => $proposal['sha256'], 'operator' => 'One operator', 'decisions' => [[
            'id' => $proposal['report']['candidates'][0]['id'], 'verdict' => 'accept', 'evidence' => 'Heard source clip 345–360',
            'audio_start' => 345.0, 'audio_end' => 360.0, 'source_sha256' => $proposal['source_sha256'], 'donor_sha256' => $proposal['donor']['sha256'],
        ]]];
    }
}
