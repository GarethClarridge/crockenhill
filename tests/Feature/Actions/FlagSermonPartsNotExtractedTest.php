<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\FlagSermonPartsNotExtracted;
use App\Enums\ServiceSectionType;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlagSermonPartsNotExtractedTest extends TestCase
{
    use RefreshDatabase;

    private FlagSermonPartsNotExtracted $hold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hold = app(FlagSermonPartsNotExtracted::class);
    }

    #[Test]
    public function it_holds_a_sermon_whose_stored_media_omits_a_recorded_part(): void
    {
        $run = $this->runWithContinuation();

        // The media was cut before the part was recorded, so it holds the sermon
        // section alone: sixteen minutes of preaching are not in the file.
        $run->update(['processing_metadata' => [
            'sermon_extraction_plan' => ['segments' => [['start_time' => 500.0, 'end_time' => 1200.0]]],
        ]]);

        $outcome = ($this->hold)($run->fresh());

        $this->assertSame(1, $outcome['raised']);

        $sermon = $run->serviceSections()->where('section_type', ServiceSectionType::Sermon->value)->sole();
        $this->assertContains(FlagSermonPartsNotExtracted::FLAG, $sermon->metadata->reviewFlags);
        $this->assertTrue((bool) $sermon->needs_manual_review);
    }

    #[Test]
    public function it_withdraws_the_hold_once_the_media_covers_every_part(): void
    {
        $run = $this->runWithContinuation();

        $run->update(['processing_metadata' => [
            'sermon_extraction_plan' => ['segments' => [['start_time' => 500.0, 'end_time' => 1200.0]]],
        ]]);
        ($this->hold)($run->fresh());

        // A re-extraction cuts both parts. The hold is derived from the comparison,
        // so it clears itself rather than waiting for a reconciliation pass.
        $run->update(['processing_metadata' => [
            'sermon_extraction_plan' => ['segments' => [
                ['start_time' => 500.0, 'end_time' => 1200.0],
                ['start_time' => 1450.0, 'end_time' => 1900.0],
            ]],
        ]]);

        $outcome = ($this->hold)($run->fresh());

        $this->assertSame(1, $outcome['withdrawn']);

        $sermon = $run->serviceSections()->where('section_type', ServiceSectionType::Sermon->value)->sole();
        $this->assertNotContains(FlagSermonPartsNotExtracted::FLAG, $sermon->metadata->reviewFlags);
        $this->assertFalse((bool) $sermon->needs_manual_review);
    }

    #[Test]
    public function it_claims_nothing_about_a_run_that_was_never_extracted_from_a_plan(): void
    {
        // No stored span means no disagreement to report. Raising here would hold a
        // run for failing to match media it does not have.
        $run = $this->runWithContinuation();

        $this->assertSame(0.0, $this->hold->unextractedSeconds($run->fresh()));
        $this->assertSame(['raised' => 0, 'withdrawn' => 0], ($this->hold)($run->fresh()));
    }

    #[Test]
    public function it_ignores_sub_second_differences_between_the_plan_and_the_media(): void
    {
        // Keyframe and silence-snapping noise is not a missing part; the smallest
        // real omission in the corpus is 1.9 minutes.
        $run = $this->runWithContinuation();

        $run->update(['processing_metadata' => [
            'sermon_extraction_plan' => ['segments' => [
                ['start_time' => 500.4, 'end_time' => 1200.0],
                ['start_time' => 1450.0, 'end_time' => 1899.8],
            ]],
        ]]);

        $this->assertSame(['raised' => 0, 'withdrawn' => 0], ($this->hold)($run->fresh()));
    }

    private function runWithContinuation(): MediaProcessingLog
    {
        $run = MediaProcessingLog::factory()->livestream()->create([
            'sermon_start_time' => 500.0,
            'sermon_end_time' => 1200.0,
        ]);

        $sermon = ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Sermon->value,
            'section_order' => 1,
            'start_time' => 500.0,
            'end_time' => 1200.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Song->value,
            'section_order' => 2,
            'start_time' => 1200.0,
            'end_time' => 1450.0,
            'needs_manual_review' => false,
            'metadata' => ['confidence_level' => 'high'],
        ]);

        ServiceSection::factory()->create([
            'media_processing_log_id' => $run->id,
            'section_type' => ServiceSectionType::Other->value,
            'section_order' => 3,
            'start_time' => 1450.0,
            'end_time' => 1900.0,
            'needs_manual_review' => false,
            'metadata' => [
                'confidence_level' => 'high',
                'sermon_continuation' => [
                    'of_section_id' => $sermon->id,
                    'evidence' => 'This is a continuation of the single sermon, separated by a congregational song.',
                    'source' => 'detector_notes',
                ],
            ],
        ]);

        return $run;
    }

    #[Test]
    public function it_keeps_the_hold_when_asked_a_second_time(): void
    {
        // The hold is derived from the plan, and the plan is built from the sermon
        // section. If raising the hold disqualified that section from auto-extraction,
        // the plan would drop to the coarse baseline, the comparison would find
        // nothing missing, and the next pass would withdraw the hold it just raised —
        // a flag that erases itself and leaves a short sermon looking clean.
        $run = $this->runWithContinuation();

        $run->update(['processing_metadata' => [
            'sermon_extraction_plan' => ['segments' => [['start_time' => 500.0, 'end_time' => 1200.0]]],
        ]]);

        $this->assertSame(1, ($this->hold)($run->fresh())['raised']);
        $this->assertSame(['raised' => 0, 'withdrawn' => 0], ($this->hold)($run->fresh()));

        $sermon = $run->serviceSections()->where('section_type', ServiceSectionType::Sermon->value)->sole();
        $this->assertContains(FlagSermonPartsNotExtracted::FLAG, $sermon->metadata->reviewFlags);
    }

    #[Test]
    public function it_still_assesses_a_run_whose_saved_sermon_text_is_stale(): void
    {
        // `sermon_text_predates_evidence` says the saved *text* was sliced from a
        // transcript the run has since replaced. It says nothing about where the
        // sermon lies, so it must not push the plan onto the baseline path — that
        // would make every run owed a re-derivation unassessable, and a later
        // re-extraction would cut the coarse span instead of the sections.
        $run = $this->runWithContinuation();

        $run->update(['processing_metadata' => [
            'sermon_extraction_plan' => ['segments' => [['start_time' => 500.0, 'end_time' => 1200.0]]],
        ]]);

        $sermon = $run->serviceSections()->where('section_type', ServiceSectionType::Sermon->value)->sole();
        $sermon->update([
            'needs_manual_review' => true,
            'metadata' => [
                'confidence_level' => 'high',
                'review_flags' => ['sermon_text_predates_evidence'],
            ],
        ]);

        $this->assertEqualsWithDelta(450.0, $this->hold->unextractedSeconds($run->fresh()), 0.1);
    }
}
