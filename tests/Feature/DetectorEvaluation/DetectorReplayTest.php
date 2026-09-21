<?php

declare(strict_types=1);

namespace Tests\Feature\DetectorEvaluation;

use App\Enums\DetectorStatus;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\DetectorEvaluation\DetectorReplay;
use App\Support\DetectorCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectorReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_a_stored_flag_against_its_detector(): void
    {
        $run = MediaProcessingLog::factory()->create();

        $this->sectionWithFlags($run, [ServiceStructureValidator::FLAG_MACRO_SECTION], held: true);

        $report = $this->replay()->over([$run]);

        $this->assertSame(1, $report['runs_read']);
        $this->assertSame(1, $report['detectors']['structure-macro-section']['signals']);
        $this->assertSame(1, $report['detectors']['structure-macro-section']['held']);
        $this->assertSame(0, $report['detectors']['structure-macro-section']['unheld']);
    }

    /**
     * The split that makes the report worth running.
     *
     * A detector firing without holding has contained nothing, so a report that
     * counted only findings would show a detector working while defects stayed
     * releasable.
     */
    public function test_it_separates_a_finding_that_held_from_one_that_did_not(): void
    {
        $run = MediaProcessingLog::factory()->create();

        $this->sectionWithFlags($run, [ServiceStructureValidator::FLAG_MICRO_SECTION], held: false);

        $report = $this->replay()->over([$run]);

        $this->assertSame(1, $report['detectors']['structure-micro-section']['signals']);
        $this->assertSame(0, $report['detectors']['structure-micro-section']['held']);
        $this->assertSame(1, $report['detectors']['structure-micro-section']['unheld']);
    }

    /**
     * A detector that produced nothing must still appear.
     *
     * Silence is either a class that does not occur or a detector that has
     * stopped working, and the two are indistinguishable in the output — which
     * is exactly why the row cannot be omitted. A table built from findings
     * alone could never contain it.
     */
    public function test_it_names_promoted_detectors_that_produced_nothing(): void
    {
        $run = MediaProcessingLog::factory()->create();

        $report = $this->replay()->over([$run]);

        $promoted = array_filter(
            DetectorCatalogue::all(),
            static fn ($entry): bool => $entry->status === DetectorStatus::Promoted,
        );

        $this->assertCount(count($promoted), $report['detectors']);
        $this->assertTrue($report['detectors']['structure-macro-section']['silent']);
    }

    /**
     * Non-emitting classes are catalogued but are not detectors, so they must
     * never appear in a report about detector output.
     */
    public function test_it_reports_no_row_for_a_class_with_no_detector(): void
    {
        $run = MediaProcessingLog::factory()->create();

        $report = $this->replay()->over([$run]);

        $this->assertArrayNotHasKey('membership-silent-source', $report['detectors']);
        $this->assertArrayNotHasKey('transcript-meaning-changing-substitution', $report['detectors']);
    }

    /**
     * A stored flag nobody claims is the coverage gap §4.3a exists to close, so
     * it is carried through and named rather than dropped. Dropping it would
     * report clean coverage of a set the report had quietly narrowed.
     */
    public function test_it_names_a_stored_flag_no_detector_claims(): void
    {
        $run = MediaProcessingLog::factory()->create();

        $this->sectionWithFlags($run, ['a_flag_from_nowhere'], held: true);

        $report = $this->replay()->over([$run]);

        $this->assertSame(
            ['section_review_flag::a_flag_from_nowhere' => 1],
            $report['uncatalogued_signals'],
        );
    }

    /**
     * An operator's containment is not detector coverage.
     *
     * `content_defect_hold` is raised precisely where no automatic screen can
     * see the defect, so counting it would credit the machinery for the cases
     * that defeated it. It is real and must be reported — in its own bucket.
     */
    public function test_it_buckets_an_operator_hold_away_from_detector_coverage(): void
    {
        $run = MediaProcessingLog::factory()->create();

        $this->sectionWithFlags($run, ['content_defect_hold'], held: true);

        $report = $this->replay()->over([$run]);

        $this->assertSame([], $report['uncatalogued_signals']);
        $this->assertSame(['section_review_flag::content_defect_hold' => 1], $report['non_detector_flags']);
    }

    /**
     * Never screened is not the same claim as screened and clear, and treating
     * it as one fills the clean column with the runs most likely to be
     * defective.
     */
    public function test_it_counts_an_unscreened_run_as_unscreened_not_clear(): void
    {
        $run = MediaProcessingLog::factory()->create();

        $report = $this->replay()->over([$run]);

        $this->assertSame(0, $report['coverage']['transcript_screened']);
        $this->assertSame(1, $report['coverage']['transcript_unscreened']);
    }

    /**
     * @param  list<string>  $flags
     */
    private function sectionWithFlags(MediaProcessingLog $run, array $flags, bool $held): ServiceSection
    {
        return ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => $held,
            'metadata' => ['review_flags' => $flags],
        ]);
    }

    private function replay(): DetectorReplay
    {
        return app(DetectorReplay::class);
    }
}
