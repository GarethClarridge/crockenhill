<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\HymnSourceWorkbookFixture;
use Tests\TestCase;

class BuildHistoricHymnReconciliationCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $workbook;

    private string $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/hymn-command-'.uniqid();
        mkdir($this->directory);

        $this->workbook = $this->directory.'/Hymn Database @ 31.12.2023.xlsx';
        $this->output = $this->directory.'/reconciliation.json';

        Song::factory()->create([
            'title' => 'Happy The People',
            'canonical_key' => Song::canonicalizeKey('Happy The People'),
        ]);

        $fixture = new HymnSourceWorkbookFixture;

        foreach (['2004', '2005', '2006', '2007', '2008', '2009', '2010', '2011', '2012', '2013', '2014', '2015', '2016', '2017', '2018', '2023'] as $year) {
            $fixture->addSheet($year, ['01.01.'.$year], [
                ['number' => '#001', 'title' => 'Happy The People', 'marks' => [0 => 'a']],
            ]);
        }

        $fixture->write($this->workbook);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    #[Test]
    public function it_writes_a_reconciliation_artifact(): void
    {
        $this->artisan('service-tracking:build-hymn-reconciliation', [
            '--source' => [$this->workbook, ...$this->remainingSources()],
            '--output' => $this->output,
        ])->assertSuccessful();

        $this->assertFileExists($this->output);

        $artifact = json_decode((string) file_get_contents($this->output), true);

        $this->assertSame('historic-hymn-reconciliation', $artifact['format']);
        $this->assertSame(19, $artifact['counts']['statements_total']);
        $this->assertSame(19, $artifact['counts']['service_known']);
        $this->assertSame('candidate_new_service', $artifact['statements'][0]['disposition']);
        $this->assertArrayHasKey('statements_sha256', $artifact);
        $this->assertSame(
            'Recorded as candidates for review, never accepted as a resolution.',
            $artifact['policy']['fuzzy_matches'],
        );
        $this->assertSame('Hymn database @ 16.08.2026.xlsx', $artifact['policy']['sheet_authority']['2025']);
        $this->assertSame('Hymn database @ 16.08.2026.xlsx', $artifact['policy']['sheet_authority']['2026']);

        $sources = collect($artifact['sources'])->keyBy('workbook');

        $this->assertSame(['2025', '2026'], $sources['Hymn database @ 16.08.2026.xlsx']['authoritative_sheets']);
        $this->assertSame(['2025', '2026'], $sources['Hymn database @ 15.03.2026.xlsx']['superseded_sheets']);
        $this->assertSame([], $sources['Hymn database @ 15.03.2026.xlsx']['authoritative_sheets']);
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_artifact(): void
    {
        file_put_contents($this->output, 'existing evidence');

        $this->artisan('service-tracking:build-hymn-reconciliation', [
            '--source' => [$this->workbook, ...$this->remainingSources()],
            '--output' => $this->output,
        ])->assertFailed();

        $this->assertSame('existing evidence', file_get_contents($this->output));
    }

    #[Test]
    public function it_requires_an_absolute_output_path(): void
    {
        $this->artisan('service-tracking:build-hymn-reconciliation', [
            '--source' => [$this->workbook, ...$this->remainingSources()],
            '--output' => 'reconciliation.json',
        ])
            ->expectsOutputToContain('absolute --output path')
            ->assertFailed();
    }

    #[Test]
    public function it_requires_at_least_one_source(): void
    {
        $this->artisan('service-tracking:build-hymn-reconciliation', [
            '--output' => $this->output,
        ])
            ->expectsOutputToContain('At least one --source workbook is required.')
            ->assertFailed();
    }

    #[Test]
    public function it_fails_when_a_year_the_policy_names_has_no_workbook(): void
    {
        $this->artisan('service-tracking:build-hymn-reconciliation', [
            '--source' => [$this->workbook],
            '--output' => $this->output,
        ])
            ->expectsOutputToContain('No supplied workbook is authoritative for: 2024, 2025, 2026.')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->output);
    }

    /**
     * The later years live in their own snapshots, so the policy is only satisfied once
     * every retained authoritative and superseded snapshot is supplied.
     *
     * @return list<string>
     */
    private function remainingSources(): array
    {
        $paths = [];

        foreach ([
            'Hymn database @ end of 2024.xlsx' => ['2024'],
            'Hymn Database @ 28.12.2025.xlsx' => ['2024', '2025'],
            'Hymn database @ 15.03.2026.xlsx' => ['2025', '2026'],
            'Hymn database @ 16.08.2026.xlsx' => ['2025', '2026'],
        ] as $name => $years) {
            $fixture = new HymnSourceWorkbookFixture;

            foreach ($years as $year) {
                $fixture->addSheet($year, ['01.01.'.$year], [
                    ['number' => '#001', 'title' => 'Happy The People', 'marks' => [0 => 'p']],
                ]);
            }

            $paths[] = $fixture->write($this->directory.'/'.$name);
        }

        return $paths;
    }
}
