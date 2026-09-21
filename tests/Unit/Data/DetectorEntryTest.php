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
            status: DetectorStatus::Unbuilt,
            severity: DetectorSeverity::WrongMetadata,
            unit: DetectorUnit::Section,
            summary: 'Nothing could ever match this.',
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
        $this->expectExceptionMessage('records no decision');

        new DetectorEntry(
            id: 'undecided',
            surface: DetectorSurface::SectionReviewFlag,
            signals: ['something'],
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
            surface: DetectorSurface::SectionReviewFlag,
            signals: ['something'],
            status: DetectorStatus::Unbuilt,
            severity: DetectorSeverity::ContentLost,
            unit: DetectorUnit::Section,
            summary: 'A known class with no response yet.',
        );

        $this->assertNull($entry->owningClass);
        $this->assertFalse($entry->isEvaluable());
    }

    public function test_only_a_promoted_scored_entry_is_evaluable(): void
    {
        $promotedS1 = $this->entry(DetectorStatus::Promoted, DetectorSeverity::PublishedWrongContent);
        $promotedS4 = $this->entry(DetectorStatus::Promoted, DetectorSeverity::TechnicalQuality);
        $prototypeS1 = $this->entry(DetectorStatus::Prototype, DetectorSeverity::PublishedWrongContent);

        $this->assertTrue($promotedS1->isEvaluable());
        $this->assertFalse($promotedS4->isEvaluable(), 'S4 is reporting-only, so there is no recall floor to protect.');
        $this->assertFalse($prototypeS1->isEvaluable(), 'A prototype is not in the pipeline, so there is nothing to score.');
    }

    public function test_it_reports_the_signals_it_emits(): void
    {
        $entry = $this->entry(DetectorStatus::Promoted, DetectorSeverity::ContentLost);

        $this->assertTrue($entry->emits('a_signal'));
        $this->assertFalse($entry->emits('another_signal'));
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
