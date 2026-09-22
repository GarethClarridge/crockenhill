<?php

declare(strict_types=1);

namespace Tests\Feature\DetectorEvaluation;

use App\Enums\ProcessingStatus;
use App\Models\ChurchService;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\DetectorEvaluation\DetectorCaseBookSource;
use App\Services\DetectorEvaluation\DetectorEvaluation;
use App\Services\DetectorEvaluation\FreezeDetectorCaseBook;
use App\Support\DetectorAcceptanceThresholds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;

class DetectorEvaluationTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    public function test_a_held_signal_on_the_case_section_contains_the_defect(): void
    {
        [$run, $section] = $this->flaggedSection(held: true);

        $report = $this->evaluate([$this->case($run, $section)]);

        $this->assertSame('contained', $report['cases'][0]['outcome']);
        $this->assertSame('section', $report['cases'][0]['match_level']);
        $this->assertSame('pass', $report['detectors']['structure-macro-section']['regression']['verdict']);
    }

    /**
     * A detector that fires without holding has contained nothing: the defect
     * still reaches readers, so the regression column fails.
     */
    public function test_an_unheld_signal_does_not_contain_the_defect(): void
    {
        [$run, $section] = $this->flaggedSection(held: false);

        $report = $this->evaluate([$this->case($run, $section)]);

        $this->assertSame('flagged_unheld', $report['cases'][0]['outcome']);
        $this->assertSame('fail', $report['detectors']['structure-macro-section']['regression']['verdict']);
        $this->assertSame('fail', $report['detectors']['structure-macro-section']['acceptance']);
    }

    public function test_a_defect_with_no_signal_is_missed(): void
    {
        [$run, $section] = $this->flaggedSection(held: true, flags: []);

        $report = $this->evaluate([$this->case($run, $section)]);

        $this->assertSame('missed', $report['cases'][0]['outcome']);
        $this->assertSame('fail', $report['detectors']['structure-macro-section']['regression']['verdict']);
    }

    /**
     * A miss held by something else, such as an operator's content hold, is
     * still this detector's miss, but it is not an uncontained defect.
     */
    public function test_a_miss_reports_whether_anything_else_holds_the_subject(): void
    {
        [$run, $section] = $this->flaggedSection(held: true, flags: ['content_defect_hold']);

        $report = $this->evaluate([$this->case($run, $section)]);

        $this->assertSame('missed', $report['cases'][0]['outcome']);
        $this->assertTrue($report['cases'][0]['subject_held']);
    }

    /**
     * Never screened is not clean: a transcript surface that was never
     * assessed on the run can neither contain nor miss anything.
     */
    public function test_a_surface_never_assessed_on_the_run_is_unassessable(): void
    {
        $run = $this->historicRun();

        $report = $this->evaluate([$this->case($run, null, detector: 'transcript-repeated-phrase-loop')]);

        $this->assertSame('unassessable', $report['cases'][0]['outcome']);
        $this->assertSame('surface_not_assessed', $report['cases'][0]['reason']);
        $this->assertSame('unassessable', $report['detectors']['transcript-repeated-phrase-loop']['regression']['verdict']);
    }

    /**
     * A class fixed at source emits nothing, so recorded output cannot show the
     * fix prevents it. That is unassessable here, never a pass.
     */
    public function test_a_class_fixed_at_source_is_unassessable_from_recorded_output(): void
    {
        $run = $this->historicRun();

        $report = $this->evaluate([$this->case($run, null, detector: 'sermon-closing-prayer-dropped')]);

        $this->assertSame('unassessable', $report['cases'][0]['outcome']);
        $this->assertSame('detector_emits_nothing', $report['cases'][0]['reason']);
        $this->assertNotSame('accepted', $report['detectors']['sermon-closing-prayer-dropped']['acceptance']);
    }

    public function test_a_signal_on_a_clean_case_is_a_false_positive(): void
    {
        [$run, $section] = $this->flaggedSection(held: false);
        [$quietRun, $quietSection] = $this->flaggedSection(held: false, flags: []);

        $report = $this->evaluate([
            $this->case($run, $section, truth: 'clean', caseId: 'fired'),
            $this->case($quietRun, $quietSection, truth: 'clean', caseId: 'quiet'),
        ]);

        $outcomes = array_column($report['cases'], 'outcome', 'case_id');
        $this->assertSame('false_positive', $outcomes['fired']);
        $this->assertSame('correctly_silent', $outcomes['quiet']);
        $this->assertSame(1, $report['detectors']['structure-macro-section']['false_positive']['false_positive_groups']);
        $this->assertSame(2, $report['detectors']['structure-macro-section']['false_positive']['clean_groups']);
    }

    /**
     * One clean group with no false positive is far too little to show a rate
     * under 5%, and the declared bound says so rather than passing it.
     */
    public function test_too_few_clean_cases_is_insufficient_evidence_not_a_pass(): void
    {
        [$run, $section] = $this->flaggedSection(held: false, flags: []);

        $report = $this->evaluate([$this->case($run, $section, truth: 'clean')]);

        $this->assertSame('insufficient_evidence', $report['detectors']['structure-macro-section']['false_positive']['verdict']);
    }

    /**
     * Provisional truth is a fixture, never a label: a re-decode's "clean" must
     * not enter the false-positive denominator.
     */
    public function test_provisional_clean_cases_do_not_enter_the_rate(): void
    {
        [$run, $section] = $this->flaggedSection(held: false);

        $report = $this->evaluate([$this->case($run, $section, truth: 'clean', basis: 'source_redecode')]);

        $falsePositive = $report['detectors']['structure-macro-section']['false_positive'];
        $this->assertSame(0, $falsePositive['clean_groups']);
        $this->assertSame(1, $falsePositive['provisional_clean_cases']);
        $this->assertSame('no_adjudicated_clean_cases', $falsePositive['verdict']);
    }

    /**
     * Correlated cases from one service are one trial, not many.
     */
    public function test_false_positives_are_clustered_by_service_group(): void
    {
        $service = ChurchService::factory()->create();
        $run = $this->historicRun($service);
        $first = $this->section($run, [ServiceStructureValidator::FLAG_MACRO_SECTION], held: false);
        $second = $this->section($run, [ServiceStructureValidator::FLAG_MACRO_SECTION], held: false);

        $report = $this->evaluate([
            $this->case($run, $first, truth: 'clean', caseId: 'a'),
            $this->case($run, $second, truth: 'clean', caseId: 'b'),
        ]);

        $this->assertSame(1, $report['detectors']['structure-macro-section']['false_positive']['clean_groups']);
        $this->assertSame(1, $report['detectors']['structure-macro-section']['false_positive']['false_positive_groups']);
    }

    /**
     * Recall comes from H10's detector-negative sample, not from regression
     * cases. Until it is drawn no detector can be accepted, however clean its
     * other columns.
     */
    public function test_recall_is_not_measured_so_nothing_is_accepted(): void
    {
        [$run, $section] = $this->flaggedSection(held: true);

        $report = $this->evaluate([$this->case($run, $section)]);
        $detector = $report['detectors']['structure-macro-section'];

        $this->assertSame('not_measured', $detector['recall']['verdict']);
        $this->assertSame('not_established', $detector['acceptance']);
    }

    public function test_the_report_binds_its_versions(): void
    {
        [$run, $section] = $this->flaggedSection(held: true);

        $report = $this->evaluate([$this->case($run, $section)]);

        foreach (['catalogue_version', 'thresholds_hash', 'case_book_hash', 'review_flag_policy_sha256', 'song_evidence_version', 'evidence'] as $key) {
            $this->assertArrayHasKey($key, $report['binding']);
        }
        $this->assertSame('recorded_output', $report['binding']['evidence']);
    }

    public function test_it_refuses_a_case_book_whose_hash_does_not_match(): void
    {
        [$run, $section] = $this->flaggedSection(held: true);
        $caseBook = $this->freeze([$this->case($run, $section)]);
        $caseBook['cases'][0]['truth']['value'] = 'clean';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('hash');

        app(DetectorEvaluation::class)->evaluate($caseBook, DetectorAcceptanceThresholds::load());
    }

    public function test_the_command_writes_a_report_and_fails_on_a_regression_failure(): void
    {
        [$run, $section] = $this->flaggedSection(held: false);
        $caseBookPath = sys_get_temp_dir().'/case-book-'.uniqid().'.json';
        $reportPath = sys_get_temp_dir().'/evaluation-'.uniqid().'.json';
        file_put_contents($caseBookPath, json_encode($this->freeze([$this->case($run, $section)])));

        try {
            $this->artisan('detectors:evaluate', ['--case-book' => $caseBookPath, '--report' => $reportPath])
                ->assertFailed();

            $report = json_decode((string) file_get_contents($reportPath), true);
            $this->assertSame('fail', $report['detectors']['structure-macro-section']['acceptance']);
        } finally {
            @unlink($caseBookPath);
            @unlink($reportPath);
        }
    }

    /**
     * @param  list<string>  $flags
     * @return array{MediaProcessingLog, ServiceSection}
     */
    private function flaggedSection(bool $held, array $flags = [ServiceStructureValidator::FLAG_MACRO_SECTION]): array
    {
        $run = $this->historicRun();

        return [$run, $this->section($run, $flags, $held)];
    }

    /**
     * @param  list<string>  $flags
     */
    private function section(MediaProcessingLog $run, array $flags, bool $held): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => $held,
            'metadata' => ['review_flags' => $flags],
        ]);
    }

    private function historicRun(?ChurchService $service = null): MediaProcessingLog
    {
        return MediaProcessingLog::factory()->create([
            'status' => ProcessingStatus::Completed,
            'church_service_id' => ($service ?? ChurchService::factory()->create())->id,
            'historic_import_operation_id' => $this->createHistoricImportOperation()->id,
            'processing_metadata' => ['historic_import' => []],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function case(
        MediaProcessingLog $run,
        ?ServiceSection $section,
        string $truth = 'defective',
        string $basis = 'source_reviewed',
        string $detector = 'structure-macro-section',
        string $caseId = 'case',
    ): array {
        return [
            'case_id' => $caseId,
            'detector_id' => $detector,
            'reference' => null,
            'subject' => ['run' => $run->id, 'section' => $section?->id, 'sermon' => null],
            'truth' => $truth,
            'basis' => $basis,
            'evidence' => 'test fixture',
            'informed_fix' => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, mixed>
     */
    private function freeze(array $cases): array
    {
        return app(FreezeDetectorCaseBook::class)->build(DetectorCaseBookSource::fromArray([
            'format' => DetectorCaseBookSource::Format,
            'version' => DetectorCaseBookSource::Version,
            'cases' => $cases,
            'aggregates' => [],
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @return array<string, mixed>
     */
    private function evaluate(array $cases): array
    {
        return app(DetectorEvaluation::class)->evaluate($this->freeze($cases), DetectorAcceptanceThresholds::load());
    }
}
