<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\DetectorEntry;
use App\Enums\DetectorSeverity;
use App\Enums\DetectorStatus;
use App\Enums\DetectorSurface;
use App\Enums\DetectorUnit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DetectorEntryTest extends TestCase
{
    public function test_it_rejects_an_entry_that_declares_no_signals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declares no signals');

        new DetectorEntry(
            id: 'signal-less',
            surface: DetectorSurface::SectionReviewFlag,
            signals: [],
            status: DetectorStatus::Promoted,
            severity: DetectorSeverity::WrongMetadata,
            unit: DetectorUnit::Section,
            summary: 'Nothing could ever match this.',
            owningClass: self::class,
        );
    }

    public function test_it_rejects_a_promoted_detector_with_no_owning_class(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name the class that emits it');

        new DetectorEntry(
            id: 'unowned',
            surface: DetectorSurface::SectionReviewFlag,
            signals: ['something'],
            status: DetectorStatus::Promoted,
            severity: DetectorSeverity::ContentLost,
            unit: DetectorUnit::Section,
            summary: 'Promoted but unrunnable.',
        );
    }

    public function test_it_rejects_a_not_detected_decision_with_no_recorded_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('records no grounds');

        new DetectorEntry(
            id: 'undecided',
            surface: null,
            signals: [],
            status: DetectorStatus::DecidedNotToDetect,
            severity: DetectorSeverity::WrongMetadata,
            unit: DetectorUnit::Section,
            summary: 'Indistinguishable from unbuilt without its decision.',
        );
    }

    public function test_an_unbuilt_entry_needs_no_owning_class(): void
    {
        $entry = new DetectorEntry(
            id: 'unbuilt',
            surface: null,
            signals: [],
            status: DetectorStatus::Unbuilt,
            severity: DetectorSeverity::ContentLost,
            unit: DetectorUnit::Section,
            summary: 'A known class with no response yet.',
        );

        $this->assertNull($entry->owningClass);
        $this->assertFalse($entry->isEvaluable());
    }

    /**
     * The other direction of the same guard, and the one that matters now that
     * the catalogue holds one entry per class-table row.
     *
     * Most of those rows are fixes, rulings and open questions rather than
     * detectors. An entry that quietly kept a surface and a signal would look
     * exactly like a promoted detector to every adapter that reads the
     * catalogue, so the harness would report coverage of a class nothing
     * watches.
     */
    public function test_it_rejects_a_non_emitting_entry_that_declares_signals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declares signals');

        new DetectorEntry(
            id: 'fixed-but-emitting',
            surface: null,
            signals: ['something'],
            status: DetectorStatus::FixedAtSource,
            severity: DetectorSeverity::ContentLost,
            unit: DetectorUnit::Sermon,
            summary: 'Fixed in code, so there is nothing left to emit.',
            decision: 'Fixed at source.',
        );
    }

    public function test_it_rejects_a_non_emitting_entry_that_names_a_surface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names a surface it never emits on');

        new DetectorEntry(
            id: 'unbuilt-with-surface',
            surface: DetectorSurface::SectionReviewFlag,
            signals: [],
            status: DetectorStatus::Unbuilt,
            severity: DetectorSeverity::WrongMetadata,
            unit: DetectorUnit::Section,
            summary: 'No response yet, so no surface either.',
        );
    }

    /**
     * A fix is an assertion that the class cannot recur, which is a stronger
     * claim than an operator choosing not to detect it. Both therefore have to
     * show their grounds, or neither is distinguishable from nobody having
     * looked.
     */
    public function test_it_rejects_a_fixed_class_with_no_recorded_grounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('records no grounds');

        new DetectorEntry(
            id: 'fixed-without-grounds',
            surface: null,
            signals: [],
            status: DetectorStatus::FixedAtSource,
            severity: DetectorSeverity::ContentLost,
            unit: DetectorUnit::Sermon,
            summary: 'Claims a fix but names none.',
        );
    }

    public function test_only_a_promoted_scored_entry_is_evaluable(): void
    {
        $promotedS1 = $this->entry(DetectorStatus::Promoted, DetectorSeverity::PublishedWrongContent);
        $promotedS4 = $this->entry(DetectorStatus::Promoted, DetectorSeverity::TechnicalQuality);
        $unbuiltS1 = $this->nonEmittingEntry(DetectorStatus::Unbuilt, DetectorSeverity::PublishedWrongContent);

        $this->assertTrue($promotedS1->isEvaluable());
        $this->assertFalse($promotedS4->isEvaluable(), 'S4 is reporting-only, so there is no recall floor to protect.');
        $this->assertFalse($unbuiltS1->isEvaluable(), 'An unbuilt class is not in the pipeline, so there is nothing to score.');
    }

    public function test_it_reports_the_signals_it_emits(): void
    {
        $entry = $this->entry(DetectorStatus::Promoted, DetectorSeverity::ContentLost);

        $this->assertTrue($entry->emits('a_signal'));
        $this->assertFalse($entry->emits('another_signal'));
    }

    private function nonEmittingEntry(DetectorStatus $status, DetectorSeverity $severity): DetectorEntry
    {
        return new DetectorEntry(
            id: 'non-emitting-example',
            surface: null,
            signals: [],
            status: $status,
            severity: $severity,
            unit: DetectorUnit::Section,
            summary: 'A class with no detector on any surface.',
        );
    }

    private function entry(DetectorStatus $status, DetectorSeverity $severity): DetectorEntry
    {
        return new DetectorEntry(
            id: 'example',
            surface: DetectorSurface::SectionReviewFlag,
            signals: ['a_signal'],
            status: $status,
            severity: $severity,
            unit: DetectorUnit::Section,
            summary: 'An example entry.',
            owningClass: self::class,
        );
    }
}
