<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Enums\ServiceSectionType;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Services\ChurchService\Structure\ValidationContext;
use App\Services\ChurchService\Structure\ValidationResult;
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
            $this->section('welcome', 0.0, 120.0, oosItemId: 1),
            $this->section('song', 120.0, 420.0, oosItemId: 6),
            $this->section('song', 420.0, 720.0, oosItemId: 2),
            $this->section('sermon', 720.0, 2300.0, oosItemId: 4),
        ]);

        $result = $this->validator->validate($structure, $this->contextWithSongBlock());

        $this->assertContains('out_of_order_oos_items', $result->failureCodes());
    }

    #[Test]
    public function block_grouped_songs_interleaved_with_other_types_pass_with_a_soft_flag(): void
    {
        // OpenLP exports group songs into a block (positions 2 and 3 here), so
        // a service that interleaves a reading between them claims positions
        // 1, 2, 4, 3, 5 — a legitimate authoring style, not a detector swap.
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0, oosItemId: 1),
            $this->section('song', 120.0, 420.0, oosItemId: 2),
            $this->section('bible_reading', 420.0, 600.0, oosItemId: 3),
            $this->section('song', 600.0, 840.0, oosItemId: 6),
            $this->section('sermon', 840.0, 2430.0, oosItemId: 4),
        ]);

        $result = $this->validator->validate($structure, $this->contextWithSongBlock());

        $this->assertNotContains('out_of_order_oos_items', $result->failureCodes());
        $this->assertTrue($result->passed(), $result->failureSummary());
        $this->assertContains(
            ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION,
            $result->structure->sections[3]->reviewFlags
        );
        $this->assertSame([], $result->structure->sections[1]->reviewFlags, 'In-order claims are unflagged.');
        $this->assertSame([], $result->structure->sections[4]->reviewFlags);
    }

    #[Test]
    public function semantic_other_items_of_different_raw_types_claimed_out_of_printed_order_pass_with_a_soft_flag(): void
    {
        // The 2024-05-05 corpus run: a `custom` "Reading" slide (position 6)
        // anchored the opening Psalm and the `presentations` children's-talk
        // PowerPoint (position 3) followed it. Both map to semantic `other`,
        // but they were never a same-type block in the printed OoS, so the
        // hard out_of_order_oos_items rule must not fire — the inversion is a
        // cross-type one and earns only the soft review flag.
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0, oosItemId: 1),
            $this->section('bible_reading', 120.0, 300.0, oosItemId: 7),
            $this->section('childrens_talk', 300.0, 800.0, oosItemId: 8),
            $this->section('sermon', 800.0, 2200.0, oosItemId: 4),
        ]);

        $result = $this->validator->validate($structure, $this->contextWithSemanticOtherCollision());

        $this->assertTrue($result->passed());
        $this->assertNotContains('out_of_order_oos_items', $result->failureCodes());
        $this->assertContains(
            ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION,
            $result->structure->sections[2]->reviewFlags
        );
    }

    #[Test]
    public function same_raw_type_items_claimed_out_of_printed_order_still_fail_hard(): void
    {
        // Two `presentations` items form a genuine same-type chain: claiming
        // the later-printed one first still signals a detector swap.
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0, oosItemId: 1),
            $this->section('other', 120.0, 300.0, oosItemId: 9),
            $this->section('childrens_talk', 300.0, 800.0, oosItemId: 8),
            $this->section('sermon', 800.0, 2200.0, oosItemId: 4),
        ]);

        $result = $this->validator->validate($structure, $this->contextWithSemanticOtherCollision());

        $this->assertFalse($result->passed());
        $this->assertContains('out_of_order_oos_items', $result->failureCodes());
    }

    #[Test]
    public function custom_items_claimed_out_of_printed_order_receive_only_a_soft_flag(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0, oosItemId: 1),
            $this->section('prayer', 120.0, 420.0, oosItemId: 8),
            $this->section('sermon', 420.0, 2200.0, oosItemId: 7),
            $this->section('song', 2200.0, 2430.0, oosItemId: 2),
        ]);
        $context = new ValidationContext(
            recordingDuration: 2430.0,
            speechDuration: 2300.0,
            oosItemTypes: [
                1 => ServiceSectionType::Welcome,
                2 => ServiceSectionType::Song,
                7 => ServiceSectionType::Sermon,
                8 => ServiceSectionType::Prayer,
            ],
            oosItemPositions: [1 => 1, 2 => 2, 7 => 3, 8 => 6],
            oosItemRawTypes: [7 => 'custom', 8 => 'custom'],
        );

        $result = $this->validator->validate($structure, $context);

        $this->assertTrue($result->passed(), $result->failureSummary());
        $this->assertNotContains('out_of_order_oos_items', $result->failureCodes());
        $this->assertContains(
            ServiceStructureValidator::FLAG_OOS_CROSS_TYPE_INVERSION,
            $result->structure->sections[2]->reviewFlags,
        );
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

    /**
     * Like context(), but with two songs authored as a block (positions 2 and
     * 3) ahead of the reading — the OpenLP grouped-by-type export shape.
     */
    private function contextWithSongBlock(): ValidationContext
    {
        return new ValidationContext(
            recordingDuration: 2430.0,
            speechDuration: 2300.0,
            oosItemTypes: [
                1 => ServiceSectionType::Welcome,
                2 => ServiceSectionType::Song,
                6 => ServiceSectionType::Song,
                3 => ServiceSectionType::BibleReading,
                4 => ServiceSectionType::Sermon,
            ],
            oosItemPositions: [1 => 1, 2 => 2, 6 => 3, 3 => 4, 4 => 5],
        );
    }

    /**
     * OoS items where two different raw OpenLP types (`custom` "Reading" at
     * position 6, `presentations` PowerPoints at positions 3 and 7) collapse
     * into the same semantic `other` bucket — the 2024-05-05 corpus shape.
     */
    private function contextWithSemanticOtherCollision(): ValidationContext
    {
        return new ValidationContext(
            recordingDuration: 2430.0,
            speechDuration: 2300.0,
            oosItemTypes: [
                1 => ServiceSectionType::Welcome,
                8 => ServiceSectionType::Other,
                4 => ServiceSectionType::Sermon,
                7 => ServiceSectionType::Other,
                9 => ServiceSectionType::Other,
            ],
            oosItemPositions: [1 => 1, 8 => 3, 4 => 5, 7 => 6, 9 => 7],
            oosItemRawTypes: [
                7 => 'custom',
                8 => 'presentations',
                9 => 'presentations',
            ],
        );
    }

    /**
     * Both pairs are the real 2026-08-26 defects, pinned verbatim: section 508
     * scored 0.980 and section 519 scored 1.000, and neither was flagged.
     */
    #[Test]
    public function a_song_section_named_differently_from_its_chapter_marker_is_flagged(): void
    {
        foreach ([
            ['How Lovely On The Mountains', 'Jesus Is Lord'],
            ['Almighty Lord Most High Draw Near', 'God of Glory'],
        ] as [$sectionTitle, $markerTitle]) {
            $result = $this->validator->validate(
                $this->structureWithSongMarker($sectionTitle, $markerTitle),
                $this->context(),
            );

            $this->assertSame(
                [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
                $this->songSectionFlags($result),
                "{$sectionTitle} vs {$markerTitle}",
            );
        }
    }

    #[Test]
    public function a_song_section_agreeing_with_its_chapter_marker_is_not_flagged(): void
    {
        $result = $this->validator->validate(
            $this->structureWithSongMarker('God Of Glory', 'God of Glory'),
            $this->context(),
        );

        $this->assertSame([], $this->songSectionFlags($result));
    }

    /**
     * The benign difference in the corpus: the catalogue carries a hymnbook
     * number the marker does not. Containment must treat that as agreement, or
     * the guard flags six correct sections for every real defect it finds.
     */
    #[Test]
    public function a_hymnbook_number_or_casing_difference_is_not_a_mismatch(): void
    {
        foreach ([
            ['Come And See #415', 'Come and See'],
            ['All Praise To Him', 'All Praise to Him'],
            ['What A Friend We Have In Jesus #614', 'What a Friend We Have in Jesus'],
        ] as [$sectionTitle, $markerTitle]) {
            $result = $this->validator->validate(
                $this->structureWithSongMarker($sectionTitle, $markerTitle),
                $this->context(),
            );

            $this->assertSame([], $this->songSectionFlags($result), "{$sectionTitle} vs {$markerTitle}");
        }
    }

    #[Test]
    public function a_song_section_with_no_covering_marker_is_not_flagged(): void
    {
        $result = $this->validator->validate(
            $this->structureWithSongMarker('How Lovely On The Mountains', 'Jesus Is Lord', markerStart: 1800.0, markerEnd: 1900.0),
            $this->context(),
        );

        $this->assertSame([], $this->songSectionFlags($result));
    }

    #[Test]
    public function a_non_song_section_is_never_flagged_for_naming_its_marker_differently(): void
    {
        $structure = ServiceStructure::fromSections(
            [$this->section('prayer', 120.0, 420.0, oosItemId: 5)],
            chapterMarkers: [['title' => 'Opening prayer', 'start_time' => 120.0, 'end_time' => 420.0]],
        );

        $result = $this->validator->validate($structure, $this->context());

        $flags = $result->structure->sections[0]->reviewFlags;

        $this->assertNotContains(ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH, $flags);
    }

    /**
     * Snapping moves section edges to silence after the markers are written, so
     * the two almost never share a boundary. Overlap, not equality, decides.
     */
    #[Test]
    public function a_marker_is_matched_by_overlap_not_by_exact_boundaries(): void
    {
        $result = $this->validator->validate(
            $this->structureWithSongMarker('How Lovely On The Mountains', 'Jesus Is Lord', markerStart: 118.4, markerEnd: 423.9),
            $this->context(),
        );

        $this->assertSame(
            [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
            $this->songSectionFlags($result),
        );
    }

    /**
     * A song section spanning two markers takes the one it overlaps most, not
     * the first one it touches.
     */
    #[Test]
    public function the_most_overlapping_marker_wins_when_a_section_spans_two(): void
    {
        $structure = ServiceStructure::fromSections(
            [$this->songSection('God Of Glory')],
            chapterMarkers: [
                ['title' => 'Call to worship', 'start_time' => 100.0, 'end_time' => 140.0],
                ['title' => 'God of Glory', 'start_time' => 140.0, 'end_time' => 420.0],
            ],
        );

        $result = $this->validator->validate($structure, $this->context());

        $this->assertSame([], $this->songSectionFlags($result));
    }

    /**
     * A concatenated historic recording is assembled from the fragments that
     * survive between the songs, which were excised for copyright — the
     * 2024-12-22 evening service is 10 fragments against 9 songs on record, and
     * 10 fragments have exactly 9 gaps. A song section there is necessarily wrong.
     */
    #[Test]
    public function song_sections_are_reclassified_on_a_recording_that_omits_songs(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('bible_reading', 0.0, 120.0, oosItemId: 3),
            $this->songSection('O Come All You Faithful'),
        ]);

        $result = $this->validator->validate($structure, $this->songLessContext());

        $song = $result->structure->sections[1];

        $this->assertSame(ServiceSectionType::Other, $song->type);
        $this->assertNull($song->songTitle);
        $this->assertNull($song->oosItemId, 'a song section must not keep its claim on an OoS item');
    }

    #[Test]
    public function a_song_less_recording_keeps_the_window_it_reclassifies(): void
    {
        $structure = ServiceStructure::fromSections([$this->songSection('O Come All You Faithful')]);

        $result = $this->validator->validate($structure, $this->songLessContext());

        $this->assertCount(1, $result->structure->sections, 'the window is reclassified, never dropped');
        $this->assertSame(120.0, $result->structure->sections[0]->startTime);
        $this->assertSame(420.0, $result->structure->sections[0]->endTime);
    }

    /**
     * The 2026-08-26 defect: four song sections over the unhearable first
     * nineteen minutes of the carol service, anchored to carol items out of
     * printed order, failing hard on a sequence the audio cannot establish.
     */
    #[Test]
    public function reclassifying_songs_removes_the_out_of_order_failure_they_caused(): void
    {
        $context = new ValidationContext(
            recordingDuration: 2430.0,
            speechDuration: 2300.0,
            oosItemTypes: [7 => ServiceSectionType::Song, 8 => ServiceSectionType::Song],
            oosItemPositions: [7 => 2, 8 => 1],
            oosItemRawTypes: [7 => 'songs', 8 => 'songs'],
            recordingOmitsSongs: true,
        );

        $structure = ServiceStructure::fromSections([
            $this->section('song', 120.0, 300.0, oosItemId: 7),
            $this->section('song', 300.0, 480.0, oosItemId: 8),
        ]);

        $withSongs = $this->validator->validate(
            $structure,
            new ValidationContext(
                recordingDuration: 2430.0,
                speechDuration: 2300.0,
                oosItemTypes: [7 => ServiceSectionType::Song, 8 => ServiceSectionType::Song],
                oosItemPositions: [7 => 2, 8 => 1],
                oosItemRawTypes: [7 => 'songs', 8 => 'songs'],
            ),
        );

        $this->assertContains('out_of_order_oos_items', $withSongs->failureCodes());
        $this->assertNotContains('out_of_order_oos_items', $this->validator->validate($structure, $context)->failureCodes());
    }

    #[Test]
    public function a_normal_recording_keeps_its_song_sections(): void
    {
        $structure = ServiceStructure::fromSections([$this->songSection('O Come All You Faithful')]);

        $result = $this->validator->validate($structure, $this->context());

        $this->assertSame(ServiceSectionType::Song, $result->structure->sections[0]->type);
        $this->assertSame('O Come All You Faithful', $result->structure->sections[0]->songTitle);
    }

    private function songLessContext(): ValidationContext
    {
        return new ValidationContext(
            recordingDuration: 2430.0,
            speechDuration: 2300.0,
            oosItemTypes: [2 => ServiceSectionType::Song, 3 => ServiceSectionType::BibleReading],
            oosItemPositions: [2 => 2, 3 => 3],
            recordingOmitsSongs: true,
        );
    }

    private function structureWithSongMarker(
        string $sectionTitle,
        string $markerTitle,
        float $markerStart = 120.0,
        float $markerEnd = 420.0,
    ): ServiceStructure {
        return ServiceStructure::fromSections(
            [$this->songSection($sectionTitle)],
            chapterMarkers: [['title' => $markerTitle, 'start_time' => $markerStart, 'end_time' => $markerEnd]],
        );
    }

    private function songSection(string $songTitle): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray([
            'type' => 'song',
            'start_time' => 120.0,
            'end_time' => 420.0,
            'confidence' => 0.98,
            'oos_item_id' => 2,
            'song_title' => $songTitle,
        ]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }

    /**
     * @return array<int, string>
     */
    private function songSectionFlags(ValidationResult $result): array
    {
        foreach ($result->structure->sections as $section) {
            if ($section->type === ServiceSectionType::Song) {
                return array_values(array_filter(
                    $section->reviewFlags,
                    static fn (string $flag): bool => $flag === ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH,
                ));
            }
        }

        return [];
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
