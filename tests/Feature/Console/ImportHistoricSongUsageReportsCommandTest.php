<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\ChurchService;
use App\Models\Song;
use App\Models\SongUsageReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class ImportHistoricSongUsageReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $workbookPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workbookPath = storage_path('framework/testing/historic-song-usage.xlsx');
        $this->writeWorkbook($this->workbookPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->workbookPath)) {
            unlink($this->workbookPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_dry_runs_by_default_and_imports_only_when_explicitly_requested(): void
    {
        Song::factory()->create([
            'title' => 'Amazing Grace #001',
            'canonical_key' => 'amazing grace #001',
            'praise_number' => '001',
        ]);

        $this->artisan('service-tracking:import-historic-song-usage-reports', ['--path' => $this->workbookPath])
            ->expectsOutputToContain('Dry run: no rows were written.')
            ->expectsOutputToContain('Rows read')
            ->assertSuccessful();

        $this->assertSame(0, SongUsageReport::query()->count());

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--import' => true,
        ])->assertSuccessful();

        $report = SongUsageReport::query()->sole();

        $this->assertSame('2007-06-17', $report->used_on->toDateString());
        $this->assertNull($report->reported_service);
        $this->assertSame('Amazing Grace', $report->reported_title);
        $this->assertSame('#001', $report->reported_number);
        $this->assertNotNull($report->song_id);
        $this->assertSame(0, ChurchService::query()->count());
    }

    #[Test]
    public function it_is_idempotent_and_retains_unmatched_rows_for_later_resolution(): void
    {
        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--import' => true,
        ])->assertSuccessful();

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--import' => true,
        ])->assertSuccessful();

        $report = SongUsageReport::query()->sole();

        $this->assertNull($report->song_id);
        $this->assertSame('Amazing Grace', $report->reported_title);
    }

    private function writeWorkbook(string $path): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Ambiguous Usage" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="str"><v>Date</v></c><c r="B1" t="str"><v>Workbook number</v></c><c r="C1" t="str"><v>Workbook title</v></c><c r="D1" t="str"><v>Catalog song ID</v></c><c r="E1" t="str"><v>Catalog title</v></c><c r="F1" t="str"><v>Match method</v></c><c r="G1" t="str"><v>External service candidates</v></c><c r="H1" t="str"><v>Resolution</v></c><c r="I1" t="str"><v>Source workbook</v></c><c r="J1" t="str"><v>Source sheet</v></c><c r="K1" t="str"><v>Source row</v></c></row><row r="2"><c r="A2" t="n"><v>39250</v></c><c r="B2" t="str"><v>#001</v></c><c r="C2" t="str"><v>Amazing Grace</v></c><c r="D2" t="n"><v>123</v></c><c r="E2" t="str"><v>Amazing Grace #001</v></c><c r="F2" t="str"><v>Title</v></c><c r="G2" t="str"/><c r="H2" t="str"><v>No external candidate</v></c><c r="I2" t="str"><v>Historic hymns.xlsx</v></c><c r="J2" t="str"><v>2007</v></c><c r="K2" t="n"><v>42</v></c></row></sheetData></worksheet>');

        $zip->close();
    }
}
