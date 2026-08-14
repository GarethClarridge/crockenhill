<?php

declare(strict_types=1);

namespace Tests\Feature\Song;

use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Services\Song\HistoricHymnReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\HymnSourceWorkbookFixture;
use Tests\TestCase;

class HistoricHymnReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const array Authority = ['2012' => 'primary.xlsx'];

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
                @rmdir(dirname($path));
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_calls_a_statement_already_represented_when_the_service_holds_the_song(): void
    {
        $song = $this->song('Happy The People');
        $service = ChurchService::factory()->create(['date' => '2012-01-01', 'service' => 'morning']);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'song_id' => $song->id,
            'type' => 'songs',
        ]);

        $artifact = $this->reconcile([['title' => 'Happy The People', 'mark' => 'a']]);

        $this->assertSame('already_represented', $artifact['statements'][0]['disposition']);
        $this->assertSame($song->id, $artifact['statements'][0]['song_id']);
        $this->assertSame($service->id, $artifact['statements'][0]['church_service_id']);
    }

    #[Test]
    public function it_calls_a_statement_review_when_the_service_exists_without_the_song(): void
    {
        $this->song('Happy The People');
        ChurchService::factory()->create(['date' => '2012-01-01', 'service' => 'morning']);

        $artifact = $this->reconcile([['title' => 'Happy The People', 'mark' => 'a']]);

        $this->assertSame('review_on_existing_service', $artifact['statements'][0]['disposition']);
    }

    #[Test]
    public function it_calls_a_statement_a_candidate_when_no_service_exists(): void
    {
        $this->song('Happy The People');

        $artifact = $this->reconcile([['title' => 'Happy The People', 'mark' => 'a']]);

        $this->assertSame('candidate_new_service', $artifact['statements'][0]['disposition']);
        $this->assertNull($artifact['statements'][0]['church_service_id']);
        $this->assertSame(1, $artifact['counts']['identities_by_disposition']['candidate_new_service']);
    }

    /**
     * A date-only mark can never become a service, whatever the corpus holds, because
     * the source did not record which of the day's two services it belongs to.
     */
    #[Test]
    public function it_never_attaches_a_date_only_statement_to_a_service(): void
    {
        $this->song('Happy The People');
        ChurchService::factory()->create(['date' => '2012-01-01', 'service' => 'morning']);

        $artifact = $this->reconcile([['title' => 'Happy The People', 'mark' => '●']]);

        $this->assertSame('date_only_service_not_recorded', $artifact['statements'][0]['disposition']);
        $this->assertNull($artifact['statements'][0]['reported_service']);
        $this->assertNull($artifact['statements'][0]['church_service_id']);
    }

    #[Test]
    public function it_records_a_fuzzy_match_as_a_candidate_rather_than_resolving_it(): void
    {
        $song = $this->song('Happy The People Whose God Is The Lord');

        $artifact = $this->reconcile([['title' => 'Happy The People Whose God Is Lord', 'mark' => 'a']]);

        $statement = $artifact['statements'][0];

        $this->assertNull($statement['song_id'], 'A fuzzy match must not be accepted as a resolution.');
        $this->assertSame($song->id, $statement['candidate_song_id']);
        $this->assertNotNull($statement['candidate_confidence']);
        $this->assertSame(1, $artifact['counts']['catalogue_fuzzy_candidates']);
    }

    #[Test]
    public function it_accepts_a_fuzzy_match_only_when_explicitly_asked(): void
    {
        $song = $this->song('Happy The People Whose God Is The Lord');

        $artifact = $this->reconcile(
            [['title' => 'Happy The People Whose God Is Lord', 'mark' => 'a']],
            acceptFuzzyMatches: true,
        );

        $this->assertSame($song->id, $artifact['statements'][0]['song_id']);
        $this->assertSame(0, $artifact['counts']['catalogue_fuzzy_candidates']);
    }

    #[Test]
    public function it_reports_the_same_song_asserted_twice_at_one_service(): void
    {
        $this->song('Happy The People');

        $artifact = $this->reconcile([
            ['title' => 'Happy The People', 'mark' => 'a'],
            ['title' => 'Happy The People', 'mark' => 'a'],
        ]);

        $this->assertSame(1, $artifact['counts']['duplicates']);
        $this->assertCount(2, $artifact['duplicates'][0]['fingerprints']);
    }

    #[Test]
    public function it_binds_every_input_the_result_depends_on(): void
    {
        $this->song('Happy The People');

        $artifact = $this->reconcile([['title' => 'Happy The People', 'mark' => 'a']]);

        $this->assertSame('primary.xlsx', $artifact['sources'][0]['workbook']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact['sources'][0]['sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact['catalogue']['fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact['corpus']['membership_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $artifact['statements_sha256']);
        $this->assertSame(self::Authority, $artifact['policy']['sheet_authority']);
        $this->assertSame(['2012'], $artifact['sources'][0]['authoritative_sheets']);
    }

    #[Test]
    public function it_refuses_a_run_where_no_workbook_covers_a_year_the_policy_names(): void
    {
        $this->song('Happy The People');
        $path = $this->workbook('primary.xlsx', [['title' => 'Happy The People', 'mark' => 'a']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/No supplied workbook is authoritative for: 2013/');

        app(HistoricHymnReconciliation::class)->generate(
            [$path],
            sheetAuthority: ['2012' => 'primary.xlsx', '2013' => 'missing.xlsx'],
        );
    }

    #[Test]
    public function it_reads_a_superseded_workbook_for_its_digest_without_counting_its_statements(): void
    {
        $this->song('Happy The People');

        $primary = $this->workbook('primary.xlsx', [['title' => 'Happy The People', 'mark' => 'a']]);
        $superseded = $this->workbook('superseded.xlsx', [['title' => 'Happy The People', 'mark' => 'p']]);

        $artifact = app(HistoricHymnReconciliation::class)->generate(
            [$primary, $superseded],
            sheetAuthority: self::Authority,
        );

        $this->assertSame(1, $artifact['counts']['statements_total']);

        $second = $artifact['sources'][1];
        $this->assertSame('superseded.xlsx', $second['workbook']);
        $this->assertSame([], $second['authoritative_sheets']);
        $this->assertSame(['2012'], $second['superseded_sheets']);
        $this->assertSame(0, $second['statements']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $second['sha256']);
    }

    /**
     * @param  list<array{title: string, mark: string}>  $hymns
     * @return array<string, mixed>
     */
    private function reconcile(array $hymns, bool $acceptFuzzyMatches = false): array
    {
        return app(HistoricHymnReconciliation::class)->generate(
            [$this->workbook('primary.xlsx', $hymns)],
            $acceptFuzzyMatches,
            self::Authority,
        );
    }

    /**
     * The factory derives `canonical_key` from its own random title, so overriding only
     * the title leaves the key pointing at something else and the exact match rung never
     * fires. Both have to move together.
     */
    private function song(string $title): Song
    {
        return Song::factory()->create([
            'title' => $title,
            'canonical_key' => Song::canonicalizeKey($title),
        ]);
    }

    /**
     * The authority policy keys on the workbook's own name, so uniqueness has to come
     * from the directory rather than from a prefix on the file.
     *
     * @param  list<array{title: string, mark: string}>  $hymns
     */
    private function workbook(string $name, array $hymns): string
    {
        $directory = sys_get_temp_dir().'/hymn-'.uniqid();
        mkdir($directory);

        $path = $directory.'/'.$name;
        $this->paths[] = $path;

        (new HymnSourceWorkbookFixture)
            ->addSheet('2012', ['40909'], array_map(
                static fn (array $hymn, int $index): array => [
                    'number' => sprintf('#%03d', $index + 1),
                    'title' => $hymn['title'],
                    'marks' => [0 => $hymn['mark']],
                ],
                $hymns,
                array_keys($hymns),
            ))
            ->write($path);

        return $path;
    }
}
