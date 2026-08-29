<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\ServiceStructure;
use App\Data\ServiceStructureSection;
use App\Services\ChurchService\Structure\SilenceSnapService;
use App\Services\Media\Audio\RmsAnalysisService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SilenceSnapServiceTest extends TestCase
{
    private SilenceSnapService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('media-processing.segmentation.rms_threshold', -45.0);
        Config::set('media-processing.service_structure.snap_window_seconds', 30);

        $this->service = new SilenceSnapService(new RmsAnalysisService);
    }

    #[Test]
    public function it_snaps_boundaries_to_the_nearest_silence_within_the_window(): void
    {
        // Silences at 118 s and 425 s; loud everywhere else.
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [60.0, -25.0],
            [118.0, -60.0],
            [200.0, -22.0],
            [300.0, -24.0],
            [425.0, -70.0],
            [500.0, -21.0],
        ]);

        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('song', 120.0, 430.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        // The shared 120 s boundary snaps to the silence at 118 s on both sides.
        $this->assertSame(118.0, $snapped->sections[0]->endTime);
        $this->assertSame(118.0, $snapped->sections[1]->startTime);
        // The song's end snaps to the silence at 425 s.
        $this->assertSame(425.0, $snapped->sections[1]->endTime);
        // Machine-readable deltas ride along for metadata.
        $this->assertSame(['start' => 0.0, 'end' => -2.0], $snapped->sections[0]->snapDeltas);
        $this->assertSame(['start' => -2.0, 'end' => -5.0], $snapped->sections[1]->snapDeltas);
    }

    #[Test]
    public function it_leaves_boundaries_unsnapped_when_no_silence_is_in_range(): void
    {
        // The only silence is 200 s away from every boundary.
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [300.0, -60.0],
            [600.0, -20.0],
        ]);

        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 30.0, 90.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertSame(30.0, $snapped->sections[0]->startTime);
        $this->assertSame(90.0, $snapped->sections[0]->endTime);
        $this->assertSame(['start' => 0.0, 'end' => 0.0], $snapped->sections[0]->snapDeltas);
        $this->assertStringContainsString('left unsnapped', implode(' ', $snapped->sections[0]->notes));
    }

    #[Test]
    public function it_reconciles_a_one_second_boundary_rounding_overlap(): void
    {
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [2400.0, -20.0],
        ]);
        $structure = ServiceStructure::fromSections([
            $this->section('bible_reading', 1200.0, 1832.0),
            $this->section('prayer', 1831.0, 1900.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertSame(1831.5, $snapped->sections[0]->endTime);
        $this->assertSame(1831.5, $snapped->sections[1]->startTime);
        $this->assertSame(['start' => 0.0, 'end' => -0.5], $snapped->sections[0]->snapDeltas);
        $this->assertSame(['start' => 0.5, 'end' => 0.0], $snapped->sections[1]->snapDeltas);
    }

    #[Test]
    public function it_does_not_hide_a_material_section_overlap(): void
    {
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [2400.0, -20.0],
        ]);
        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),
            $this->section('song', 100.0, 400.0),
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertSame(120.0, $snapped->sections[0]->endTime);
        $this->assertSame(100.0, $snapped->sections[1]->startTime);
    }

    #[Test]
    public function shared_boundaries_snap_together_and_stay_chronological(): void
    {
        // Silences at 95 s and 155 s. Each shared boundary must move to the
        // same silence on both sides, keeping the sections contiguous.
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [95.0, -60.0],
            [155.0, -60.0],
            [210.0, -20.0],
        ]);

        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 120.0),   // midpoint 60
            $this->section('song', 120.0, 180.0),    // midpoint 150
            $this->section('prayer', 180.0, 240.0),  // midpoint 210
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        // Boundary 120 (welcome end / song start): the silence at 95 s is the
        // only one in the 30 s window and lies between midpoints 60 and 150.
        $this->assertSame(95.0, $snapped->sections[0]->endTime);
        $this->assertSame(95.0, $snapped->sections[1]->startTime);

        // Boundary 180 (song end / prayer start): nearest silence 155 s is
        // within the window and between midpoints 150 and 210 — legal.
        $this->assertSame(155.0, $snapped->sections[1]->endTime);
        $this->assertSame(155.0, $snapped->sections[2]->startTime);

        // Sections remain chronological and non-overlapping after snapping.
        $previousEnd = 0.0;
        foreach ($snapped->sections as $section) {
            $this->assertGreaterThan($section->startTime, $section->endTime);
            $this->assertGreaterThanOrEqual($previousEnd, $section->startTime);
            $previousEnd = $section->endTime;
        }
    }

    #[Test]
    public function a_silence_beyond_a_neighbours_midpoint_is_rejected(): void
    {
        // The only silence near the 100 s boundary is at 130 s — beyond the
        // second section's midpoint (125 s), so the boundary must stay put.
        $rmsLog = $this->rmsLog([
            [0.0, -20.0],
            [130.0, -60.0],
            [200.0, -20.0],
        ]);

        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 100.0),
            $this->section('song', 100.0, 150.0), // midpoint 125
        ]);

        $snapped = $this->service->snap($structure, $rmsLog);

        $this->assertSame(100.0, $snapped->sections[0]->endTime);
        $this->assertSame(100.0, $snapped->sections[1]->startTime);
    }

    #[Test]
    public function it_uses_the_calibrated_adaptive_threshold_when_the_log_has_enough_samples(): void
    {
        // 1,000+ samples engage the same adaptive thresholding the
        // segmentation pipeline uses. The bottom 30% of this log sits at
        // -55 dB, so the calibrated threshold is -55 — under the fixed -45
        // threshold the -48 dB dip at 100 s would wrongly count as silence.
        $samples = [];

        for ($time = 0; $time < 1050; $time++) {
            $samples[] = [(float) $time, match (true) {
                $time === 100 => -48.0,
                $time >= 700 => -55.0,
                default => -25.0,
            }];
        }

        $structure = ServiceStructure::fromSections([
            $this->section('welcome', 0.0, 110.0),
            $this->section('song', 110.0, 220.0),
        ]);

        $snapped = $this->service->snap($structure, $this->rmsLog($samples));

        // The -48 dB dip is not silence under the calibrated threshold and no
        // true silence is within the window, so the boundary stays put.
        $this->assertSame(110.0, $snapped->sections[0]->endTime);
        $this->assertSame(110.0, $snapped->sections[1]->startTime);
        $this->assertStringContainsString('left unsnapped', implode(' ', $snapped->sections[0]->notes));
    }

    #[Test]
    public function an_empty_or_silence_free_log_leaves_the_structure_untouched(): void
    {
        $structure = ServiceStructure::fromSections([
            $this->section('sermon', 100.0, 2000.0),
        ]);

        $snapped = $this->service->snap($structure, $this->rmsLog([[0.0, -20.0], [500.0, -25.0]]));

        $this->assertSame($structure->toArray(), $snapped->toArray());
    }

    private function section(string $type, float $start, float $end): ServiceStructureSection
    {
        $section = ServiceStructureSection::fromArray([
            'type' => $type,
            'start_time' => $start,
            'end_time' => $end,
            'confidence' => 0.9,
        ]);

        assert($section instanceof ServiceStructureSection);

        return $section;
    }

    /**
     * Build ffmpeg-astats-style RMS log content from [time, rms] pairs.
     *
     * @param  list<array{0: float, 1: float}>  $samples
     */
    private function rmsLog(array $samples): string
    {
        $lines = [];

        foreach ($samples as $index => [$time, $rms]) {
            $lines[] = sprintf('frame:%d pts:%d pts_time:%.3f', $index, (int) ($time * 8000), $time);
            $lines[] = sprintf('lavfi.astats.Overall.RMS_level=%.1f', $rms);
        }

        return implode("\n", $lines)."\n";
    }
}
