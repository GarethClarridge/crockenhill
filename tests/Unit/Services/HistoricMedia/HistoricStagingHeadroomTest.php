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
        $report = app(HistoricStagingHeadroom::class)->report(
            [
                ['bytes' => 8 * self::GIB],
                ['bytes' => 6 * self::GIB],
                ['bytes' => 2 * self::GIB],
            ],
            parallel: 2,
            minimumFreeGb: 20,
        );

        $this->assertSame(3, $report['item_count']);
        $this->assertSame(16 * self::GIB, $report['selected_source_bytes']);
        $this->assertSame(8 * self::GIB, $report['largest_source_bytes']);

        /** FFmpeg reads a source and writes beside it, so two in flight need twice their sum. */
        $this->assertSame(28 * self::GIB, $report['concurrent_working_bytes']);
        $this->assertSame(48 * self::GIB, $report['required_free_bytes']);
    }

    #[Test]
    public function it_sizes_one_job_in_flight_even_when_parallel_is_zero(): void
    {
        $report = app(HistoricStagingHeadroom::class)->report(
            [['bytes' => 5 * self::GIB]],
            parallel: 0,
            minimumFreeGb: 0,
        );

        $this->assertSame(10 * self::GIB, $report['required_free_bytes']);
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

        $report = app(HistoricStagingHeadroom::class)->report([['bytes' => 1]], 1, 20);

        $this->assertFalse($report['measurable']);
        $this->assertStringContainsString('CBC_HISTORIC_WORK_PATH', $report['host_command']);
    }

    #[Test]
    public function it_reports_measurable_free_space_when_the_volume_can_be_read(): void
    {
        Config::set('media-processing.storage.temp_disk_unmeasurable', false);

        $report = app(HistoricStagingHeadroom::class)->report([['bytes' => 1]], 1, 20);

        $this->assertTrue($report['measurable']);
        $this->assertIsInt($report['process_reported_free_bytes']);
    }

    #[Test]
    public function it_handles_an_empty_pass(): void
    {
        $report = app(HistoricStagingHeadroom::class)->report([], 4, 20);

        $this->assertSame(0, $report['item_count']);
        $this->assertSame(0, $report['largest_source_bytes']);
        $this->assertSame(20 * self::GIB, $report['required_free_bytes']);
    }
}
