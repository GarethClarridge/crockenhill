<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Support\SectionReviewFlagPolicy;
use App\Support\SermonAutoExtractionPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SectionReviewFlagPolicyTest extends TestCase
{
    #[Test]
    public function no_flags_never_requires_manual_review(): void
    {
        $this->assertFalse(SectionReviewFlagPolicy::requiresManualReview(ServiceSectionType::Song, []));
        $this->assertFalse(SectionReviewFlagPolicy::requiresManualReview(ServiceSectionType::Notices, []));
    }

    #[Test]
    public function cross_type_inversion_never_forces_review(): void
    {
        $this->assertFalse(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Sermon,
            [ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION],
        ));
    }

    #[Test]
    public function low_confidence_and_micro_only_matter_on_structural_uncertainty_types(): void
    {
        // Filler types: demoted.
        $this->assertFalse(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Notices,
            [ServiceStructureValidator::FLAG_LOW_CONFIDENCE],
        ));
        $this->assertFalse(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Welcome,
            [ServiceStructureValidator::FLAG_MICRO_SECTION],
        ));

        // Structural-uncertainty types: still reviewed.
        $this->assertTrue(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Sermon,
            [ServiceStructureValidator::FLAG_LOW_CONFIDENCE],
        ));
    }

    #[Test]
    public function oos_structure_mismatch_is_demoted_on_non_publishable_filler_only(): void
    {
        // OD-1: welcome/notices/prayer/other carrying only an OoS structure
        // mismatch are no longer forced into review — the mismatch is about OoS
        // ordering, not the filler section itself.
        $this->assertFalse(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Welcome,
            [ServiceStructureValidator::FLAG_OOS_STRUCTURE_MISMATCH],
        ));
        $this->assertFalse(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Notices,
            [ServiceStructureValidator::FLAG_OOS_STRUCTURE_MISMATCH],
        ));

        // A mismatched sermon still warrants a look — its boundaries matter.
        $this->assertTrue(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Sermon,
            [ServiceStructureValidator::FLAG_OOS_STRUCTURE_MISMATCH],
        ));
    }

    #[Test]
    public function a_suspected_closing_benediction_never_forces_review(): void
    {
        // A short closing bible_reading flagged as a suspected benediction is
        // filler-by-position, never the preached text — no operator action.
        $this->assertFalse(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::BibleReading,
            [ServiceStructureValidator::FLAG_BENEDICTION_SUSPECT],
        ));
    }

    #[Test]
    public function an_unrecognised_flag_still_forces_review(): void
    {
        $this->assertTrue(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Notices,
            ['ambiguous_sermon_detection'],
        ));
    }

    #[Test]
    public function a_demotable_flag_alongside_a_hard_flag_still_forces_review(): void
    {
        $this->assertTrue(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Notices,
            [ServiceStructureValidator::FLAG_OOS_STRUCTURE_MISMATCH, 'unmatched_song_section'],
        ));
    }

    /**
     * The two ordering flags are deliberately treated differently. A cross-type
     * inversion fires on ~42% of services with OoS claims — reviewing them all
     * would be noise. A same-type inversion fires on ~6%, and is the stronger
     * signal, so it is worth an operator's eye.
     */
    #[Test]
    public function a_same_type_oos_inversion_forces_review_though_a_cross_type_one_does_not(): void
    {
        $this->assertTrue(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Song,
            [ServiceStructureValidator::FLAG_OOS_SAME_TYPE_INVERSION],
        ));

        $this->assertFalse(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Song,
            [ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION],
        ));
    }

    #[Test]
    public function a_merged_interrupted_sermon_forces_review(): void
    {
        $this->assertTrue(SectionReviewFlagPolicy::requiresManualReview(
            ServiceSectionType::Sermon,
            [ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED],
        ));
    }

    /**
     * Ordering flags question which OoS item a section aligns to, never its
     * boundaries, so they must not demote extraction. The merge flag moves the
     * sermon's own boundaries, so it must.
     */
    #[Test]
    public function ordering_flags_permit_auto_extraction_but_the_sermon_merge_flag_does_not(): void
    {
        $this->assertTrue(SermonAutoExtractionPolicy::reviewStatePermitsAutoExtraction(
            true,
            [ServiceStructureValidator::FLAG_OOS_SAME_TYPE_INVERSION],
        ));

        $this->assertFalse(SermonAutoExtractionPolicy::reviewStatePermitsAutoExtraction(
            true,
            [ServiceStructureValidator::FLAG_SERMON_INTERRUPTION_MERGED],
        ));
    }

    #[Test]
    public function a_material_sermon_boundary_flag_never_permits_auto_extraction(): void
    {
        $this->assertFalse(SermonAutoExtractionPolicy::reviewStatePermitsAutoExtraction(
            false,
            [ServiceStructureValidator::FLAG_SERMON_BOUNDARY_MATERIAL_RISK],
        ));
    }
}
