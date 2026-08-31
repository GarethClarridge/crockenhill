<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HistoricMedia;

use App\Services\HistoricMedia\HistoricStagingHeadroom;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricStagingHeadroomTest extends TestCase
{
    private const GIB = 1024 ** 3;

    #[Test]
    public function it_sizes_a_pass_from_its_largest_concurrent_work(): void
    {
        Config::set('media-processing.historic_import.stages.ffmpeg.workers', 2);

        $report = app(HistoricStagingHeadroom::class)->report(
            [
                ['bytes' => 8 * self::GIB],
                ['bytes' => 6 * self::GIB],
                ['bytes' => 2 * self::GIB],
            ],
            minimumFreeGb: 20,
        );

        $this->assertSame(3, $report['item_count']);
        $this->assertSame(16 * self::GIB, $report['selected_source_bytes']);
        $this->assertSame(16 * self::GIB, $report['selected_unstaged_input_bytes']);
        $this->assertSame(8 * self::GIB, $report['largest_source_bytes']);
        $this->assertSame(2, $report['configured_worker_widths']['ffmpeg']);

        /** FFmpeg reads a source and writes beside it, so two in flight need twice their sum. */
        $this->assertSame(28 * self::GIB, $report['concurrent_working_bytes']);
        $this->assertSame(64 * self::GIB, $report['required_free_bytes']);
    }

    #[Test]
    public function it_excludes_inputs_that_are_already_staged_from_the_precopy_term(): void
    {
        Config::set('media-processing.historic_import.stages.ffmpeg.workers', 2);

        $report = app(HistoricStagingHeadroom::class)->report(
            [
                ['bytes' => 5 * self::GIB, 'already_staged' => true],
                ['bytes' => 3 * self::GIB],
            ],
            minimumFreeGb: 0,
        );

        $this->assertSame(8 * self::GIB, $report['selected_input_bytes']);
        $this->assertSame(3 * self::GIB, $report['selected_unstaged_input_bytes']);
        $this->assertSame(16 * self::GIB, $report['concurrent_transient_bytes']);
        $this->assertSame(19 * self::GIB, $report['required_free_bytes']);
    }

    /**
     * The plan this work came from was sized against "30 GB available of 461 GB",
     * read from inside the container, where `disk_free_space()` reports the host's
     * boot volume rather than the bind-mounted drive — which holds 444 GiB free.
     * The gates already stood down; nothing said so out loud.
     */
    #[Test]
    public function it_declares_the_free_space_unknowable_when_the_volume_is_unmeasurable(): void
    {
        Config::set('media-processing.storage.temp_disk_unmeasurable', true);

        $report = app(HistoricStagingHeadroom::class)->report([['bytes' => 1]], 20);

        $this->assertFalse($report['measurable']);
        $this->assertStringContainsString('CBC_HISTORIC_WORK_PATH', $report['host_command']);
    }

    #[Test]
    public function it_reports_measurable_free_space_when_the_volume_can_be_read(): void
    {
        Config::set('media-processing.storage.temp_disk_unmeasurable', false);

        $report = app(HistoricStagingHeadroom::class)->report([['bytes' => 1]], 20);

        $this->assertTrue($report['measurable']);
        $this->assertIsInt($report['process_reported_free_bytes']);
    }

    #[Test]
    public function it_handles_an_empty_pass(): void
    {
        $report = app(HistoricStagingHeadroom::class)->report([], 20);

        $this->assertSame(0, $report['item_count']);
        $this->assertSame(0, $report['largest_source_bytes']);
        $this->assertSame(20 * self::GIB, $report['required_free_bytes']);
    }

    #[Test]
    public function it_reports_retained_review_sources_without_double_counting_them_in_required_headroom(): void
    {
        Config::set('media-processing.storage.temp_disk_unmeasurable', true);

        $report = app(HistoricStagingHeadroom::class)->report(
            [['bytes' => 4 * self::GIB]],
            minimumFreeGb: 20,
            reviewSourceRetainedBytes: 7 * self::GIB,
        );

        $this->assertSame(7 * self::GIB, $report['review_source_retained_bytes']);
        $this->assertSame(32 * self::GIB, $report['required_free_bytes']);
    }
}
