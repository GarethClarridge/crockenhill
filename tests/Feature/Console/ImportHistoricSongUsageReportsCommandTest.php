<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SermonPublicationState;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\HistoricImportOperation;
use App\Models\Song;
use App\Models\SongUsageReport;
use App\Services\Song\HistoricSongUsageCloseout;
use App\Services\Song\SongUsageQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesHistoricImportOperations;
use Tests\TestCase;
use ZipArchive;

class ImportHistoricSongUsageReportsCommandTest extends TestCase
{
    use CreatesHistoricImportOperations;
    use RefreshDatabase;

    private string $workbookPath;

    /** @var array<string, HistoricImportOperation> */
    private array $operations = [];

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
            '--operation' => $this->operationForWorkbook()->operation_id,
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
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
        ])->assertSuccessful();

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
        ])->assertSuccessful();

        $report = SongUsageReport::query()->sole();

        $this->assertNull($report->song_id);
        $this->assertSame('Amazing Grace', $report->reported_title);
    }

    /**
     * F62: the defect that made a rerun report success it had not achieved. The row is
     * imported unmatched, the catalogue is then corrected, and the rerun must neither
     * silently update nor claim the row is resolved while it is still stored unmatched.
     */
    #[Test]
    public function it_holds_a_catalogue_correction_until_the_resolution_is_authorised(): void
    {
        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
        ])->assertSuccessful();

        $this->assertNull(SongUsageReport::query()->sole()->song_id);

        $song = Song::factory()->create([
            'title' => 'Amazing Grace #001',
            'canonical_key' => 'amazing grace #001',
            'praise_number' => '001',
        ]);

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
        ])
            ->expectsOutputToContain('Resolution available (not authorised)')
            ->assertSuccessful();

        $this->assertNull(
            SongUsageReport::query()->sole()->song_id,
            'An unauthorised rerun must not change a stored resolution.',
        );

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
            '--resolve-catalogue-matches' => true,
        ])
            ->expectsOutputToContain('Resolution applied')
            ->assertSuccessful();

        $this->assertSame($song->id, SongUsageReport::query()->sole()->song_id);

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
            '--resolve-catalogue-matches' => true,
        ])
            ->expectsOutputToContain('Unchanged')
            ->assertSuccessful();

        $this->assertSame($song->id, SongUsageReport::query()->sole()->song_id);
    }

    /** F62: an occurrence must never move between songs as a side effect of a rerun. */
    #[Test]
    public function it_refuses_to_move_an_occurrence_between_songs(): void
    {
        Song::factory()->create([
            'title' => 'Amazing Grace #001',
            'canonical_key' => 'amazing grace #001',
            'praise_number' => '001',
        ]);

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
        ])->assertSuccessful();

        $other = Song::factory()->create(['title' => 'A completely different song']);
        $report = SongUsageReport::query()->sole();
        $report->forceFill(['song_id' => $other->id])->save();

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
            '--resolve-catalogue-matches' => true,
        ])
            ->expectsOutputToContain('resolution conflict')
            ->assertFailed();

        $this->assertSame(
            $other->id,
            SongUsageReport::query()->sole()->song_id,
            'A conflicted rerun must fail with zero writes.',
        );
    }

    /**
     * F62: the fingerprint covers only workbook/sheet/row/date/title, so a changed
     * catalogue title or number under the same fingerprint is drift, not a new row.
     */
    #[Test]
    public function it_fails_before_writes_when_a_stored_source_field_drifts(): void
    {
        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
        ])->assertSuccessful();

        $this->writeWorkbook($this->workbookPath, reportedNumber: '#999');

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
        ])
            ->expectsOutputToContain('source drift')
            ->assertFailed();

        $this->assertSame('#001', SongUsageReport::query()->sole()->reported_number);
        $this->assertSame(1, SongUsageReport::query()->count());
    }

    /** F62: a linked report leaves the occurrence union exactly once, and only when authorised. */
    #[Test]
    public function it_links_a_canonical_occurrence_only_when_authorised(): void
    {
        $song = Song::factory()->create([
            'title' => 'Amazing Grace #001',
            'canonical_key' => 'amazing grace #001',
            'praise_number' => '001',
        ]);

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
        ])->assertSuccessful();

        $service = ChurchService::factory()->create(['date' => '2007-06-17']);
        $item = ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
        ])
            ->expectsOutputToContain('Canonical link available (not authorised)')
            ->assertSuccessful();

        $this->assertNull(SongUsageReport::query()->sole()->resolved_church_service_item_id);

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
            '--link-canonical-occurrences' => true,
        ])
            ->expectsOutputToContain('Canonical link applied')
            ->assertSuccessful();

        $this->assertSame($item->id, SongUsageReport::query()->sole()->resolved_church_service_item_id);

        /**
         * The point of linking: the canonical item now supplies the occurrence, so the report
         * must leave the union rather than double-counting the same Sunday.
         */
        $occurrences = app(SongUsageQuery::class)->occurrences(publicOnly: false)
            ->where('song_id', $song->id)
            ->get();

        $this->assertCount(1, $occurrences);
        $this->assertSame("service:{$service->id}", $occurrences->first()?->service_identity);
    }

    /** F61: an unowned write is the state this lane used to be in permanently. */
    #[Test]
    public function it_refuses_to_persist_without_an_owning_operation(): void
    {
        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--import' => true,
        ])
            ->expectsOutputToContain('must name its immutable operation')
            ->assertFailed();

        $this->assertSame(0, SongUsageReport::query()->count());
    }

    /** F61: the approved artifact is verified as bytes, not trusted as a path. */
    #[Test]
    public function it_refuses_a_workbook_that_is_not_the_one_the_operation_approved(): void
    {
        $operation = $this->operationForWorkbook();
        $this->writeWorkbook($this->workbookPath, reportedNumber: '#777');

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $operation->operation_id,
            '--import' => true,
        ])
            ->expectsOutputToContain('does not match the digest the operation approved')
            ->assertFailed();

        $this->assertSame(0, SongUsageReport::query()->count());
    }

    /** F61: the recorded dry-run contract refuses count drift before the first write. */
    #[Test]
    public function it_refuses_a_run_that_does_not_match_its_approved_contract(): void
    {
        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
            '--expect-rows' => '2',
            '--expect-resolved' => '2',
            '--expect-unresolved' => '0',
        ])
            ->expectsOutputToContain('does not match its approved contract')
            ->assertFailed();

        $this->assertSame(0, SongUsageReport::query()->count());

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $this->operationForWorkbook()->operation_id,
            '--import' => true,
            '--expect-rows' => '1',
            '--expect-resolved' => '0',
            '--expect-unresolved' => '1',
        ])->assertSuccessful();

        $this->assertSame(1, SongUsageReport::query()->count());
    }

    /**
     * F61: what the lane leaves behind — rows owned by the operation, quarantined, and an
     * import report naming every one of them so closeout can check they are all still there.
     */
    #[Test]
    public function it_binds_written_rows_to_the_operation_and_reports_their_membership(): void
    {
        $operation = $this->operationForWorkbook();

        $this->artisan('service-tracking:import-historic-song-usage-reports', [
            '--path' => $this->workbookPath,
            '--operation' => $operation->operation_id,
            '--import' => true,
        ])->assertSuccessful();

        $report = SongUsageReport::query()->sole();

        $this->assertSame($operation->id, $report->historic_import_operation_id);
        $this->assertSame(SermonPublicationState::Quarantined, $report->publication_state);

        $this->assertDatabaseHas('historic_import_journal_entries', [
            'historic_import_operation_id' => $operation->id,
            'event' => 'song_usage_imported',
        ]);

        $closeout = app(HistoricSongUsageCloseout::class);

        $closeout->assertReconciled($operation->operation_id);

        $this->assertSame(
            ['quarantined' => 1, 'published' => 0],
            $closeout->stateCounts($operation->operation_id),
        );

        /** F61: closeout cannot pass once an approved row has gone missing. */
        $report->delete();

        $this->expectExceptionMessage('membership does not reconcile');

        $closeout->assertReconciled($operation->operation_id);
    }

    /**
     * An immutable operation bound to the workbook as it stands right now.
     *
     * Resolved lazily rather than in setUp because a test that rewrites the workbook is
     * approving a different artifact, and the operation must name that one's digest.
     */
    private function operationForWorkbook(): HistoricImportOperation
    {
        $digest = (string) hash_file('sha256', $this->workbookPath);

        return $this->operations[$digest] ??= $this->createHistoricImportOperation(attributes: [
            'manifest_hashes' => ['historic_song_usage' => $digest],
        ]);
    }

    private function writeWorkbook(string $path, string $reportedNumber = '#001'): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Ambiguous Usage" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="str"><v>Date</v></c><c r="B1" t="str"><v>Workbook number</v></c><c r="C1" t="str"><v>Workbook title</v></c><c r="D1" t="str"><v>Catalog song ID</v></c><c r="E1" t="str"><v>Catalog title</v></c><c r="F1" t="str"><v>Match method</v></c><c r="G1" t="str"><v>External service candidates</v></c><c r="H1" t="str"><v>Resolution</v></c><c r="I1" t="str"><v>Source workbook</v></c><c r="J1" t="str"><v>Source sheet</v></c><c r="K1" t="str"><v>Source row</v></c></row><row r="2"><c r="A2" t="n"><v>39250</v></c><c r="B2" t="str"><v>'.$reportedNumber.'</v></c><c r="C2" t="str"><v>Amazing Grace</v></c><c r="D2" t="n"><v>123</v></c><c r="E2" t="str"><v>Amazing Grace #001</v></c><c r="F2" t="str"><v>Title</v></c><c r="G2" t="str"/><c r="H2" t="str"><v>No external candidate</v></c><c r="I2" t="str"><v>Historic hymns.xlsx</v></c><c r="J2" t="str"><v>2007</v></c><c r="K2" t="n"><v>42</v></c></row></sheetData></worksheet>');

        $zip->close();
    }
}
