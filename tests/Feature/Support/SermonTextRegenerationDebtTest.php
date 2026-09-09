<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Actions\FlagSermonPartsNotExtracted;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Support\SermonTextRegenerationDebt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonTextRegenerationDebtTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_missing_preached_reading_does_not_leave_the_span_in_question(): void
    {
        // The flag's own reasoning, recorded on both the validator and
        // SermonAutoExtractionPolicy: it questions what surrounds the sermon, not
        // the sermon's own boundaries — "the sermon span extracts correctly either
        // way". Holding a re-slice on it blocked 26 owed runs for a fact that
        // cannot move the bounds being sliced.
        $run = $this->runHeldBy(ServiceStructureValidator::FLAG_MISSING_PREACHED_READING);

        $this->assertArrayNotHasKey($run->id, SermonTextRegenerationDebt::runsWithUnsettledSpans());
    }

    #[Test]
    public function a_material_boundary_risk_does_not_leave_the_span_in_question(): void
    {
        // The recorded policy is to publish the inclusive span and let a reviewer
        // decide afterwards. Re-slicing the text to that same inclusive span makes
        // the text and the media agree; it does not pre-empt the reviewer.
        $run = $this->runHeldBy(ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK);

        $this->assertArrayNotHasKey($run->id, SermonTextRegenerationDebt::runsWithUnsettledSpans());
    }

    #[Test]
    public function a_sermon_missing_recorded_parts_does_leave_the_span_in_question(): void
    {
        // This is the guard the two flags above were only doing by accident. A run
        // whose media omits a recorded sermon part must not have its text re-sliced
        // to the short span — that would bank text nobody has settled and withdraw
        // the staleness hold that says so.
        $run = $this->runHeldBy(FlagSermonPartsNotExtracted::FLAG);

        $this->assertArrayHasKey($run->id, SermonTextRegenerationDebt::runsWithUnsettledSpans());
    }

    #[Test]
    public function an_uncertain_section_type_still_leaves_the_span_in_question(): void
    {
        // Kept deliberately, though the release gate excludes it: an uncertain type
        // can mean an `other` section is really sermon, which changes what a
        // re-slice should select.
        $run = $this->runHeldBy(ServiceStructureValidator::FLAG_LOW_CONFIDENCE);

        $this->assertArrayHasKey($run->id, SermonTextRegenerationDebt::runsWithUnsettledSpans());
    }

    #[Test]
    public function an_over_long_song_on_the_run_still_leaves_the_span_in_question(): void
    {
        // The P8-Q15 second shape: preaching buried inside an over-long "song"
        // moves where the sermon's material lies even though the sermon section
        // looks clean, so the hold is read from the whole run, not the sermon alone.
        $run = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'start_time' => 1200.0,
            'end_time' => 2400.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => [ServiceStructureValidator::FLAG_MACRO_SECTION],
            ],
        ]);

        $this->assertArrayHasKey($run->id, SermonTextRegenerationDebt::runsWithUnsettledSpans());
    }

    private function runHeldBy(string $flag): MediaProcessingLog
    {
        $run = MediaProcessingLog::factory()->livestream()->create();

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => [$flag, 'sermon_text_predates_evidence'],
            ],
        ]);

        return $run;
    }
}
