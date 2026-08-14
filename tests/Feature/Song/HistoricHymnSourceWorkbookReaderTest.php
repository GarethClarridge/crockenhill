<?php

declare(strict_types=1);

namespace Tests\Feature\Song;

use App\Services\Song\HistoricHymnSourceWorkbookReader;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\HymnSourceWorkbookFixture;
use Tests\TestCase;

class HistoricHymnSourceWorkbookReaderTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/hymn-source-'.uniqid().'.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_reads_the_service_from_the_cell_marker(): void
    {
        (new HymnSourceWorkbookFixture)
            ->addSheet('2012', ['40909'], [
                ['number' => '#001', 'title' => 'Happy The People', 'marks' => [0 => 'a']],
                ['number' => '#002', 'title' => 'Amazing Grace', 'marks' => [0 => 'p']],
            ])
            ->write($this->path);

        $reading = (new HistoricHymnSourceWorkbookReader)->read($this->path);

        $this->assertSame([], $reading['anomalies']);
        $this->assertCount(2, $reading['statements']);

        $this->assertSame('morning', $reading['statements'][0]['reported_service']);
        $this->assertSame('Happy The People', $reading['statements'][0]['reported_title']);
        $this->assertSame('#001', $reading['statements'][0]['reported_number']);
        $this->assertSame('2012-01-01', $reading['statements'][0]['used_on']);
        $this->assertSame('2012', $reading['statements'][0]['source_sheet']);

        $this->assertSame('evening', $reading['statements'][1]['reported_service']);
    }

    #[Test]
    public function it_keeps_a_date_only_mark_date_only(): void
    {
        (new HymnSourceWorkbookFixture)
            ->addSheet('2007', ['39083'], [
                ['number' => '#001', 'title' => 'Happy The People', 'marks' => [0 => '●']],
            ])
            ->write($this->path);

        $reading = (new HistoricHymnSourceWorkbookReader)->read($this->path);

        $this->assertCount(1, $reading['statements']);
        $this->assertNull($reading['statements'][0]['reported_service']);
        $this->assertTrue($reading['statements'][0]['marker_recognised']);
        $this->assertSame([], $reading['anomalies']);
    }

    /**
     * The 2026-08-09 workbook turned one whitespace-only cell into a usage statement,
     * which is the whole difference between its 1,941 date-only rows and the 1,940 the
     * source supports. A blank marks nothing and must be reported, not counted.
     */
    #[Test]
    public function it_reports_a_whitespace_only_cell_instead_of_counting_it(): void
    {
        (new HymnSourceWorkbookFixture)
            ->addSheet('2010', ['40503'], [
                ['number' => '#879', 'title' => 'Trouble May Break With The Dawn', 'marks' => [0 => ' ']],
            ])
            ->write($this->path);

        $reading = (new HistoricHymnSourceWorkbookReader)->read($this->path);

        $this->assertSame([], $reading['statements']);
        $this->assertCount(1, $reading['anomalies']);
        $this->assertSame('blank_marker', $reading['anomalies'][0]['kind']);
        $this->assertSame(3, $reading['anomalies'][0]['source_row']);
        $this->assertSame('2010-11-21', $reading['anomalies'][0]['used_on']);
    }

    #[Test]
    public function it_keeps_an_unrecognised_mark_as_a_statement_and_reports_it(): void
    {
        (new HymnSourceWorkbookFixture)
            ->addSheet('2012', ['40909'], [
                ['number' => '#001', 'title' => 'Happy The People', 'marks' => [0 => '?']],
            ])
            ->write($this->path);

        $reading = (new HistoricHymnSourceWorkbookReader)->read($this->path);

        $this->assertCount(1, $reading['statements']);
        $this->assertNull($reading['statements'][0]['reported_service']);
        $this->assertFalse($reading['statements'][0]['marker_recognised']);
        $this->assertSame('?', $reading['statements'][0]['source_marker']);
        $this->assertSame('unrecognised_marker', $reading['anomalies'][0]['kind']);
    }

    #[Test]
    public function it_reads_dates_written_as_text_as_well_as_serials(): void
    {
        (new HymnSourceWorkbookFixture)
            ->addSheet('2023', ['01.01.2023'], [
                ['number' => '#001', 'title' => 'Happy The People', 'marks' => [0 => 'a']],
            ])
            ->write($this->path);

        $reading = (new HistoricHymnSourceWorkbookReader)->read($this->path);

        $this->assertSame('2023-01-01', $reading['statements'][0]['used_on']);
    }

    #[Test]
    public function it_ignores_the_running_total_columns(): void
    {
        (new HymnSourceWorkbookFixture)
            ->addSheet('2012', ['40909'], [
                ['number' => '#001', 'title' => 'Happy The People', 'marks' => [0 => 'a']],
            ], yearTotalColumn: '2011')
            ->write($this->path);

        $reading = (new HistoricHymnSourceWorkbookReader)->read($this->path);

        $this->assertCount(1, $reading['statements']);
        $this->assertSame('2012-01-01', $reading['statements'][0]['used_on']);
    }

    /** A year sheet legitimately runs into the first Sunday of the next year. */
    #[Test]
    public function it_accepts_a_date_that_spills_into_the_next_year(): void
    {
        (new HymnSourceWorkbookFixture)
            ->addSheet('2023', ['07.01.2024'], [
                ['number' => '#001', 'title' => 'Happy The People', 'marks' => [0 => 'a']],
            ])
            ->write($this->path);

        $reading = (new HistoricHymnSourceWorkbookReader)->read($this->path);

        $this->assertSame('2024-01-07', $reading['statements'][0]['used_on']);
    }

    #[Test]
    public function it_refuses_a_date_more_than_a_year_from_its_sheet(): void
    {
        (new HymnSourceWorkbookFixture)
            ->addSheet('2012', ['01.01.2020'], [
                ['number' => '#001', 'title' => 'Happy The People', 'marks' => [0 => 'a']],
            ])
            ->write($this->path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/more than a year from its tab/');

        (new HistoricHymnSourceWorkbookReader)->read($this->path);
    }

    #[Test]
    public function it_reads_only_the_sheets_it_is_asked_for(): void
    {
        (new HymnSourceWorkbookFixture)
            ->addSheet('2012', ['40909'], [
                ['number' => '#001', 'title' => 'Happy The People', 'marks' => [0 => 'a']],
            ])
            ->addSheet('2013', ['41275'], [
                ['number' => '#002', 'title' => 'Amazing Grace', 'marks' => [0 => 'p']],
            ])
            ->write($this->path);

        $reading = (new HistoricHymnSourceWorkbookReader)->read($this->path, ['2013']);

        $this->assertCount(1, $reading['statements']);
        $this->assertSame('2013', $reading['statements'][0]['source_sheet']);
    }
}
