<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Actions\FlagSermonTextPredatesEvidence;
use App\Data\ChurchServiceTranscript;
use App\Enums\ProcessingStatus;
use App\Enums\SermonPublicationState;
use App\Enums\ServiceSectionType;
use App\Models\HistoricImportOperation;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

/**
 * P8-Q14's recovery replaced the full-service transcripts and left every saved
 * sermon text sliced from the transcript it replaced. This is the pass that
 * re-slices them, and the hold it must withdraw when it does.
 */
class RegenerateOwedSermonTextCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    /** Sliced from the transcript recovery replaced, loop and all. */
    private const StaleText = 'The sermon itself. The sermon itself. The sermon itself.';

    private const RecoveredText = 'The sermon itself.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('historic_quarantine');
        Config::set('media-processing.storage.temp_disk', 'local');
        Config::set('media-processing.storage.transcript_disk', 'local');
        Config::set('media-processing.storage.sermon_disk', 'local');
    }

    #[Test]
    public function it_reports_the_regeneration_without_writing_by_default(): void
    {
        [$log, $sermon] = $this->owedRun();

        $this->artisan('historic-import:regenerate-owed-sermon-text')
            ->expectsOutputToContain('1 run(s) selected')
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame(self::StaleText, $this->savedText($sermon));
        $this->assertTrue($log->fresh()->sermonDerivationIsOwed());
    }

    /**
     * The whole point of the pass: the text a reader sees is re-sliced from the
     * transcript that replaced the looping one, and the hold that said so is
     * withdrawn by the writer that made it false.
     */
    #[Test]
    public function it_re_slices_the_text_and_withdraws_the_hold(): void
    {
        [$log, $sermon, $section] = $this->owedRun();

        $this->assertTrue($section->fresh()->needs_manual_review);

        $this->artisan('historic-import:regenerate-owed-sermon-text', ['--execute' => true])
            ->expectsOutputToContain('Regenerated 1 sermon text(s).')
            ->assertSuccessful();

        $this->assertSame(self::RecoveredText, $this->savedText($sermon));
        $this->assertFalse($log->fresh()->sermonDerivationIsOwed());

        $section = $section->fresh();
        $this->assertNotContains(
            FlagSermonTextPredatesEvidence::FLAG,
            $section->metadata->reviewFlags ?? [],
        );
        $this->assertFalse($section->needs_manual_review);
    }

    /**
     * Re-slicing to spans nobody has settled would clear the hold while banking
     * text no one has agreed is right — the laundering the hold exists to stop.
     */
    #[Test]
    public function it_holds_back_a_run_whose_spans_are_still_in_question(): void
    {
        [$log, $sermon] = $this->owedRun();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => [ServiceStructureValidator::FLAG_MACRO_SECTION]],
        ]);

        $this->artisan('historic-import:regenerate-owed-sermon-text', ['--execute' => true])
            ->expectsOutputToContain('0 run(s) selected; 1 held back')
            ->assertSuccessful();

        $this->assertSame(self::StaleText, $this->savedText($sermon));
        $this->assertTrue($log->fresh()->sermonDerivationIsOwed());
    }

    #[Test]
    public function include_unsettled_takes_the_held_back_run_anyway(): void
    {
        [$log, $sermon] = $this->owedRun();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Song,
            'needs_manual_review' => true,
            'metadata' => ['review_flags' => [ServiceStructureValidator::FLAG_MACRO_SECTION]],
        ]);

        $this->artisan('historic-import:regenerate-owed-sermon-text', [
            '--execute' => true,
            '--include-unsettled' => true,
        ])->assertSuccessful();

        $this->assertSame(self::RecoveredText, $this->savedText($sermon));
    }

    /**
     * The gate that separates this pass from the span repair. That command
     * skips a single-span plan because the outer bounds select the same cues;
     * here the *transcript* changed, so a single-span run is exactly the case
     * that needs re-slicing.
     */
    #[Test]
    public function it_regenerates_a_single_span_run_that_the_span_repair_skips(): void
    {
        [$log, $sermon] = $this->owedRun(segments: [['start_time' => 400.0, 'end_time' => 500.0]]);

        $this->artisan('historic-import:repair-sermon-transcript-spans')
            ->expectsOutputToContain('0 transcript(s) would be repaired.')
            ->assertSuccessful();

        $this->artisan('historic-import:regenerate-owed-sermon-text', ['--execute' => true])
            ->expectsOutputToContain('Regenerated 1 sermon text(s).')
            ->assertSuccessful();

        $this->assertSame(self::RecoveredText, $this->savedText($sermon));
    }

    /**
     * A run whose saved text already equals a fresh slice is not "nothing to
     * do": its stamp never caught up, so without reconciliation the hold stands
     * forever on text that is demonstrably right. Five real runs sat in exactly
     * that state after the first pass.
     */
    #[Test]
    public function it_reconciles_a_run_whose_text_already_matches_the_current_derivation(): void
    {
        [$log, $sermon, $section] = $this->owedRun();

        // The text is already what a fresh slice produces; only the stamp lags.
        Storage::disk('historic_quarantine')->put((string) $sermon->transcript_file_path, self::RecoveredText);

        $this->assertTrue($log->fresh()->sermonDerivationIsOwed());

        $this->artisan('historic-import:regenerate-owed-sermon-text', ['--execute' => true])
            ->expectsOutputToContain('Regenerated 0 sermon text(s).')
            ->expectsOutputToContain('Reconciled 1 run(s)')
            ->assertSuccessful();

        $this->assertFalse($log->fresh()->sermonDerivationIsOwed());
        $this->assertFalse($section->fresh()->needs_manual_review);
        $this->assertSame(self::RecoveredText, $this->savedText($sermon));
    }

    /** A failed run's spans were never settled by a completed pipeline. */
    #[Test]
    public function it_leaves_a_failed_run_alone(): void
    {
        [$log, $sermon] = $this->owedRun();
        $log->forceFill(['status' => ProcessingStatus::Failed])->save();

        $this->artisan('historic-import:regenerate-owed-sermon-text', ['--execute' => true])
            ->expectsOutputToContain('No run is owed a settled sermon re-derivation.')
            ->assertSuccessful();

        $this->assertSame(self::StaleText, $this->savedText($sermon));
    }

    /**
     * @param  list<array{start_time: float, end_time: float}>|null  $segments
     * @return array{0: MediaProcessingLog, 1: Sermon, 2: ServiceSection}
     */
    private function owedRun(?HistoricImportOperation $operation = null, ?array $segments = null): array
    {
        $operation ??= $this->createHistoricImportOperation();
        $segments ??= [['start_time' => 400.0, 'end_time' => 500.0]];

        $log = MediaProcessingLog::factory()->livestream()->completed()->create([
            'historic_import_operation_id' => $operation->id,
            'sermon_start_time' => $segments[0]['start_time'],
            'sermon_end_time' => $segments[count($segments) - 1]['end_time'],
            'processing_metadata' => [
                'historic_import' => ['operation_id' => $operation->operation_id],
                'sermon_extraction_plan' => ['segments' => $segments],
            ],
        ]);

        $sermon = Sermon::factory()->fromLivestream()->create([
            'livestream_processing_id' => $log->processing_id,
            'historic_import_operation_id' => $operation->id,
            'publication_state' => SermonPublicationState::Quarantined,
            'asset_disk' => 'historic_quarantine',
        ]);
        $log->forceFill(['sermon_id' => $sermon->id])->save();

        $transcriptPath = 'transcripts/sermon_'.$sermon->id.'.md';
        $sermon->forceFill(['transcript_file_path' => $transcriptPath])->save();
        Storage::disk('historic_quarantine')->put($transcriptPath, self::StaleText);

        $transcript = ChurchServiceTranscript::fromCues([
            ['start' => 400.0, 'end' => 500.0, 'text' => self::RecoveredText],
        ], 600.0, ChurchServiceTranscript::SOURCE_MOCK);

        $serviceTranscriptPath = 'service-transcripts/2024-10-06/morning-'.$log->processing_id.'.recovered.json';
        Storage::disk('local')->put($serviceTranscriptPath, json_encode($transcript->toArray(), JSON_THROW_ON_ERROR));
        $log->putServiceTranscriptPath($serviceTranscriptPath);

        // Recovery replaced the transcript and stamped it; the sermon text was
        // never re-sliced, which is precisely what leaves the derivation owed.
        $log->recordServiceTranscriptContent(MediaProcessingLog::hashServiceTranscriptContent($transcript));

        $section = ServiceSection::factory()->create([
            'media_processing_log_id' => $log->id,
            'section_type' => ServiceSectionType::Sermon,
            'start_time' => $segments[0]['start_time'],
            'end_time' => $segments[count($segments) - 1]['end_time'],
            'needs_manual_review' => false,
            'metadata' => ['review_flags' => []],
        ]);

        app(FlagSermonTextPredatesEvidence::class)($log->refresh());

        return [$log->refresh(), $sermon->fresh(), $section->fresh()];
    }

    private function savedText(Sermon $sermon): string
    {
        return (string) Storage::disk('historic_quarantine')->get((string) $sermon->transcript_file_path);
    }
}
