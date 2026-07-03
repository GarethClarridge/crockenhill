<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\ValidationContext;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceStructureValidatorTest extends TestCase
{
    private ServiceStructureValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.service_structure.coverage_floor', 0.7);
        Config::set('media-processing.service_structure.min_section_seconds', 15);
        Config::set('media-processing.segmentation.min_sermon_duration', 300.0);
        Config::set('media-processing.section_extraction.enhanced_sermon.max_sermon_duration_seconds', 2700);
        Config::set('media-processing.reading_references.benediction_max_duration_seconds', 60);

        $this->validator = new ServiceStructureValidator;
    }

    #[Test]
    public function a_clean_structure_passes_with_no_flags(): void
    {
        $result = $this->validator->validate($this->cleanStructure(), $this->context());

        $this->assertTrue($result->passed());
        $this->assertSame([], $result->hardFailures);
        $this->assertSame([], $result->unmatchedOosItemIds);

        foreach ($result->structure->sections as $section) {
            $this->assertSame([], $section->reviewFlags);
        }
    }

    #[Test]
    public function an_empty_structure_fails_hard(): void
    {
        $result = $this->validator->validate(new ServiceStructure([]), $this->context());

        $this->assertFalse($result->passed());
        $this->assertSame(['no_sections'], $result->failureCodes());
    }

    #[Test]
    public function overlapping_sections_fail_hard(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0, oosItemId: 1),
            $this->section('song', 100.0, 400.0, oosItemId: 2), // overlaps welcome by 20 s
            $this->section('sermon', 500.0, 2200.0, oosItemId: 4),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertContains('non_chronological', $result->failureCodes());
    }

    #[Test]
    public function a_zero_duration_section_fails_hard(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 0.0),
            $this->section('sermon', 500.0, 2200.0),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertContains('non_chronological', $result->failureCodes());
    }

    #[Test]
    public function timestamps_beyond_the_recording_fail_hard(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('sermon', 500.0, 2200.0),
            $this->section('song', 2210.0, 3000.0), // recording is 2430 s
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertContains('timestamps_outside_recording', $result->failureCodes());
    }

    #[Test]
    public function coverage_below_the_floor_fails_hard(): void
    {
        // ~2300 s of speech; a lone welcome covers 5% of it.
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertContains('insufficient_coverage', $result->failureCodes());
    }

    #[Test]
    public function coverage_measures_cue_overlap_not_raw_section_duration(): void
    {
        // All 2,000 s of speech happen in the first 2,000 s; the proposal is
        // one long section in the silent back half of the recording. Its raw
        // duration would satisfy the floor — its cue overlap is zero.
        $context = new ValidationContext(
            recordingDuration: 6000.0,
            speechDuration: 2000.0,
            cues: [
                ['start' => 0.0, 'end' => 1000.0, 'text' => 'First half of the service.'],
                ['start' => 1000.0, 'end' => 2000.0, 'text' => 'Second half of the service.'],
            ],
        );

        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 3500.0, 5500.0),
        ]);

        $result = $this->validator->validate($structure, $context);

        $this->assertContains('insufficient_coverage', $result->failureCodes());

        // The same section placed over the actual speech passes the floor.
        $coveringStructure = ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 2000.0),
        ]);

        $coveringResult = $this->validator->validate($coveringStructure, $context);

        $this->assertNotContains('insufficient_coverage', $coveringResult->failureCodes());
    }

    #[Test]
    public function two_sermons_fail_hard(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 1000.0),
            $this->section('sermon', 1100.0, 2200.0),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertContains('multiple_sermons', $result->failureCodes());
    }

    #[Test]
    public function an_implausibly_long_sermon_fails_hard(): void
    {
        $context = new ValidationContext(recordingDuration: 6000.0, speechDuration: 4000.0);

        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 0.0, 3000.0), // beyond 2700 s ceiling
            $this->section('song', 3100.0, 4200.0),
        ]);

        $result = $this->validator->validate($structure, $context);

        $this->assertContains('sermon_duration_out_of_bounds', $result->failureCodes());
    }

    #[Test]
    public function an_implausibly_short_sermon_fails_hard(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 200.0),
            $this->section('sermon', 300.0, 400.0), // 100 s, below the 300 s minimum
            $this->section('song', 500.0, 2300.0),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertContains('sermon_duration_out_of_bounds', $result->failureCodes());
    }

    #[Test]
    public function a_missing_sermon_is_not_a_hard_failure(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 400.0),
            $this->section('song', 400.0, 1200.0),
            $this->section('prayer', 1200.0, 2300.0),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertTrue($result->passed());
    }

    #[Test]
    public function an_unknown_oos_item_fails_hard(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 400.0, oosItemId: 999),
            $this->section('sermon', 500.0, 2200.0),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertContains('unknown_oos_item', $result->failureCodes());
    }

    #[Test]
    public function a_duplicated_oos_item_fails_hard(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('song', 0.0, 400.0, oosItemId: 2),
            $this->section('song', 500.0, 900.0, oosItemId: 2),
            $this->section('sermon', 1000.0, 2300.0),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertContains('duplicate_oos_item', $result->failureCodes());
    }

    #[Test]
    public function a_type_incompatible_oos_item_fails_hard(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('song', 0.0, 400.0, oosItemId: 4), // item 4 is a sermon
            $this->section('sermon', 500.0, 2200.0),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertContains('incompatible_oos_item', $result->failureCodes());
    }

    #[Test]
    public function same_type_oos_items_claimed_out_of_planned_order_fail_hard(): void
    {
        // Two songs swapped by the detector pass the existence, duplicate and
        // type checks — only their planned positions expose the mix-up.
        $structure = ServiceStructure::fromSections([
            $this->section('bible_reading', 0.0, 200.0, oosItemId: 3),
            $this->section('song', 300.0, 700.0, oosItemId: 2),
            $this->section('welcome', 800.0, 1000.0, oosItemId: 1),
            $this->section('sermon', 1100.0, 2300.0, oosItemId: 4),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertContains('out_of_order_oos_items', $result->failureCodes());
    }

    #[Test]
    public function items_claimed_in_planned_order_with_gaps_pass(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('song', 0.0, 400.0, oosItemId: 2),
            $this->section('sermon', 500.0, 2200.0, oosItemId: 4),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertNotContains('out_of_order_oos_items', $result->failureCodes());
        $this->assertTrue($result->passed(), $result->failureSummary());
    }

    #[Test]
    public function a_generic_other_oos_item_may_anchor_any_section_type(): void
    {
        // Item 5 is semantic type "other" ("Andrew Talk.pptx") anchoring the sermon — F15.
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 400.0, oosItemId: 1),
            $this->section('sermon', 500.0, 2200.0, oosItemId: 5),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertTrue($result->passed(), $result->failureSummary());
    }

    #[Test]
    public function low_confidence_sections_get_a_soft_flag(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 400.0, confidence: 0.5),
            $this->section('sermon', 500.0, 2200.0, confidence: 0.95),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertTrue($result->passed());
        $this->assertContains(ServiceStructureValidator::FLAG_LOW_CONFIDENCE, $result->structure->sections[0]->reviewFlags);
        $this->assertSame([], $result->structure->sections[1]->reviewFlags);
    }

    #[Test]
    public function micro_sections_get_a_soft_flag(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('other', 0.0, 10.0), // below 15 s
            $this->section('sermon', 500.0, 2200.0),
            $this->section('song', 2210.0, 2400.0),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertTrue($result->passed());
        $this->assertContains(ServiceStructureValidator::FLAG_MICRO_SECTION, $result->structure->sections[0]->reviewFlags);
    }

    #[Test]
    public function a_short_reading_at_the_end_of_the_service_is_flagged_as_a_possible_benediction(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('bible_reading', 100.0, 300.0, oosItemId: 3),
            $this->section('sermon', 400.0, 2100.0, oosItemId: 4),
            $this->section('bible_reading', 2370.0, 2420.0), // 50 s, ends 10 s before the recording ends
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertTrue($result->passed());
        $this->assertSame([], $result->structure->sections[0]->reviewFlags, 'The mid-service reading is untouched.');
        $this->assertContains(
            ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT,
            $result->structure->sections[2]->reviewFlags
        );
    }

    #[Test]
    public function unmatched_oos_items_are_reported_softly(): void
    {
        $result = $this->validator->validate($this->cleanStructure(), $this->context());
        $this->assertSame([], $result->unmatchedOosItemIds);

        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 400.0, oosItemId: 1),
            $this->section('sermon', 500.0, 2200.0, oosItemId: 4),
        ]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertTrue($result->passed());
        $this->assertSame([2, 3, 5], $result->unmatchedOosItemIds);
    }

    /**
     * A structure that satisfies every rule against context(): chronological,
     * covering, one plausible sermon, every OoS item claimed compatibly.
     */
    private function cleanStructure(): ServiceStructure
    {
        return ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0, oosItemId: 1),
            $this->section('song', 120.0, 420.0, oosItemId: 2),
            $this->section('bible_reading', 420.0, 600.0, oosItemId: 3),
            $this->section('sermon', 600.0, 2200.0, oosItemId: 4),
            $this->section('prayer', 2200.0, 2430.0, oosItemId: 5),
        ]);
    }

    /**
     * 2430 s recording with 2300 s of speech; OoS items 1–5 in planned order
     * (5 is generic "other").
     */
    private function context(): ValidationContext
    {
        return new ValidationContext(
            recordingDuration: 2430.0,
            speechDuration: 2300.0,
            oosItemTypes: [
                1 => ServiceSectionType::Welcome,
                2 => ServiceSectionType::Song,
                3 => ServiceSectionType::BibleReading,
                4 => ServiceSectionType::Sermon,
                5 => ServiceSectionType::Other,
            ],
            oosItemPositions: [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5],
        );
    }

    private function section(
        string $type,
        float $start,
        float $end,
        ?int $oosItemId = null,
        float $confidence = 0.95,
    ): ServiceStructureSection {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => $confidence,
            'oos_item_id' => $oosItemId,
        ]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }
}
