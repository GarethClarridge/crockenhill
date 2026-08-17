<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\ChurchServiceSourceRecord;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * IC3 item 0(2): the item-level ground truth that lets extraction quality be measured at all.
 *
 * The behaviours pinned here are the ones that make the artifact trustworthy rather than merely
 * produced — foreign song ids are re-resolved, a source never corroborates evidence it produced,
 * and an unresolvable title is reported as indeterminate rather than counted as disagreement.
 */
class BuildHistoricItemGroundTruthCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir().'/ground-truth-'.bin2hex(random_bytes(6));
        mkdir($root);
        mkdir("{$root}/raw");
        $this->root = $root;
    }

    #[Test]
    public function it_reports_agreement_across_both_corroborating_sources(): void
    {
        $songs = $this->catalogue(['Amazing Grace', 'Be Thou My Vision', 'In Christ Alone']);

        $this->stageService('2023-01-01', 'morning', [
            ['type' => 'custom', 'title' => 'Welcome'],
            ['type' => 'songs', 'title' => 'Amazing Grace'],
            ['type' => 'songs', 'title' => 'Be Thou My Vision'],
        ]);

        $this->writeArchive('2023-01-01', 'morning', [
            ['type' => 'custom', 'title' => 'Welcome'],
            ['type' => 'songs', 'title' => 'Amazing Grace'],
            ['type' => 'songs', 'title' => 'Be Thou My Vision'],
        ]);

        $artifact = $this->build([
            $this->statement('2023-01-01', 'morning', 'Amazing Grace'),
            $this->statement('2023-01-01', 'morning', 'Be Thou My Vision'),
        ]);

        $identity = $artifact['identities'][0];

        $this->assertSame(['hymn_workbook', 'openlp'], $identity['corroborated_by']);
        $this->assertSame([
            'song_membership' => 'match',
            'song_count' => 'match',
            'song_order' => 'match',
        ], $identity['verdicts']);
        $this->assertSame(1, $artifact['counts']['by_source']['both']);
        $this->assertSame(0, $artifact['counts']['by_source']['none']);
        $this->assertSame($songs['Amazing Grace'], $identity['staged']['song_sequence'][0]);
    }

    #[Test]
    public function it_reports_an_uncorroborated_identity_rather_than_omitting_it(): void
    {
        $this->catalogue(['Amazing Grace']);
        $this->stageService('2020-06-07', 'evening', [
            ['type' => 'songs', 'title' => 'Amazing Grace'],
        ]);
        $this->writeArchive('2023-01-01', 'morning', [['type' => 'custom', 'title' => 'Welcome']]);

        $artifact = $this->build([]);

        $identity = $artifact['identities'][0];

        $this->assertSame([], $identity['corroborated_by']);
        $this->assertSame([
            'song_membership' => 'not_corroborated',
            'song_count' => 'not_corroborated',
            'song_order' => 'not_corroborated',
        ], $identity['verdicts']);
        $this->assertSame(['2020' => 1], $artifact['counts']['uncorroborated_by_year']);
        $this->assertSame(0, $artifact['counts']['corroborated_identities']);
    }

    #[Test]
    public function it_detects_a_song_the_workbook_records_but_the_extraction_missed(): void
    {
        $songs = $this->catalogue(['Amazing Grace', 'Be Thou My Vision']);

        $this->stageService('2023-01-01', 'morning', [
            ['type' => 'songs', 'title' => 'Amazing Grace'],
        ]);

        $artifact = $this->build([
            $this->statement('2023-01-01', 'morning', 'Amazing Grace'),
            $this->statement('2023-01-01', 'morning', 'Be Thou My Vision'),
        ]);

        $identity = $artifact['identities'][0];

        $this->assertSame('mismatch', $identity['verdicts']['song_membership']);
        $this->assertSame([$songs['Be Thou My Vision']], $identity['hymn_workbook']['membership']['missing_from_staged']);
        $this->assertSame([], $identity['hymn_workbook']['membership']['extra_in_staged']);
        $this->assertSame([
            'total' => 1,
            'missing_from_staged_only' => 1,
            'extra_in_staged_only' => 0,
            'both_directions' => 0,
            'explainable_by_an_unresolved_title' => 0,
        ], $artifact['counts']['song_membership_mismatches']);
    }

    #[Test]
    public function it_detects_songs_extracted_in_the_wrong_order(): void
    {
        $this->catalogue(['Amazing Grace', 'Be Thou My Vision']);

        $this->stageService('2023-01-01', 'morning', [
            ['type' => 'songs', 'title' => 'Be Thou My Vision'],
            ['type' => 'songs', 'title' => 'Amazing Grace'],
        ]);

        $this->writeArchive('2023-01-01', 'morning', [
            ['type' => 'songs', 'title' => 'Amazing Grace'],
            ['type' => 'songs', 'title' => 'Be Thou My Vision'],
        ]);

        $artifact = $this->build([]);
        $identity = $artifact['identities'][0];

        $this->assertSame('mismatch', $identity['verdicts']['song_order']);
        // The item *types* agree even though the songs are transposed, which is exactly the
        // distinction `item_type_or_order` could not previously be resolved into.
        $this->assertTrue($identity['openlp']['order_shape']['type_sequences_identical']);
        $this->assertSame('match', $identity['verdicts']['song_count']);
    }

    #[Test]
    public function projection_only_items_are_recorded_as_shape_and_never_scored(): void
    {
        $this->catalogue(['Amazing Grace']);

        // An order of service lists spoken items; the deck carries slides for neither of them
        // and adds images of its own. Only the song is comparable.
        $this->stageService('2023-01-01', 'morning', [
            ['type' => 'custom', 'title' => 'Welcome'],
            ['type' => 'custom', 'title' => 'Notices'],
            ['type' => 'songs', 'title' => 'Amazing Grace'],
        ]);

        $this->writeArchive('2023-01-01', 'morning', [
            ['type' => 'images', 'title' => 'Background'],
            ['type' => 'songs', 'title' => 'Amazing Grace'],
            ['type' => 'presentations', 'title' => 'Sermon slides'],
        ]);

        $artifact = $this->build([]);
        $identity = $artifact['identities'][0];

        $this->assertSame('match', $identity['verdicts']['song_count']);
        $this->assertSame('match', $identity['verdicts']['song_order']);

        $shape = $identity['openlp']['order_shape'];
        $this->assertSame(3, $shape['staged_item_count']);
        $this->assertSame(3, $shape['openlp_item_count']);
        $this->assertFalse($shape['type_sequences_identical']);
        $this->assertArrayNotHasKey('item_count', $identity['verdicts']);
        $this->assertArrayNotHasKey('item_sequence', $identity['verdicts']);
    }

    #[Test]
    public function it_refuses_to_let_openlp_corroborate_a_service_openlp_produced(): void
    {
        $this->catalogue(['Amazing Grace']);

        $this->stageService('2023-01-01', 'morning', [
            ['type' => 'songs', 'title' => 'Amazing Grace'],
        ], source: ChurchServiceItemSource::OpenLp);

        $this->writeArchive('2023-01-01', 'morning', [
            ['type' => 'songs', 'title' => 'Amazing Grace'],
        ]);

        $artifact = $this->build([]);
        $identity = $artifact['identities'][0];

        $this->assertSame('circular', $identity['verdicts']['song_count']);
        $this->assertSame('circular', $identity['verdicts']['song_order']);
        $this->assertNull($identity['openlp']['song_count']);
        $this->assertNull($identity['openlp']['song_order']);
    }

    #[Test]
    public function it_re_resolves_workbook_titles_instead_of_trusting_a_foreign_song_id(): void
    {
        $songs = $this->catalogue(['Amazing Grace', 'Be Thou My Vision']);

        $this->stageService('2023-01-01', 'morning', [
            ['type' => 'songs', 'title' => 'Amazing Grace'],
        ]);

        // The artifact was generated against another catalogue where this id meant another song.
        $statement = $this->statement('2023-01-01', 'morning', 'Amazing Grace');
        $statement['song_id'] = $songs['Be Thou My Vision'];

        $artifact = $this->build([$statement]);
        $identity = $artifact['identities'][0];

        $this->assertSame([$songs['Amazing Grace']], $identity['hymn_workbook']['song_ids']);
        $this->assertSame('match', $identity['verdicts']['song_membership']);
    }

    #[Test]
    public function an_unresolvable_title_is_indeterminate_rather_than_a_mismatch(): void
    {
        $this->catalogue(['Amazing Grace']);

        $this->stageService('2023-01-01', 'morning', [
            ['type' => 'songs', 'title' => "574 'one holy apostolic church' (can we try and pick a well known t"],
        ]);

        $this->writeArchive('2023-01-01', 'morning', [
            ['type' => 'songs', 'title' => 'Amazing Grace'],
        ]);

        $artifact = $this->build([]);
        $identity = $artifact['identities'][0];

        $this->assertSame('indeterminate', $identity['verdicts']['song_order']);
        $this->assertSame(1, $artifact['counts']['staged_song_items']['unresolved']);
        $this->assertArrayHasKey(
            "574 'one holy apostolic church' (can we try and pick a well known t",
            $artifact['unresolved_staged_song_titles'],
        );
    }

    /**
     * The scoring block exists so a parser comparison has a denominator no arm can move.
     *
     * A service the extraction produced no songs for is scored `indeterminate`, and a population
     * filtered on scoreable verdicts would drop it — removing an extraction's total failures from
     * its own denominator and letting a run that extracted nothing score as well as one that
     * extracted correctly. Evidence availability is the only exclusion, because it is the only
     * property of an identity that no parse can change.
     */
    #[Test]
    public function it_keeps_an_empty_extraction_in_the_scored_population(): void
    {
        $this->catalogue(['Amazing Grace', 'Be Thou My Vision']);

        $this->stageService('2023-01-01', 'morning', [
            ['type' => 'custom', 'title' => 'Welcome'],
        ]);

        $this->writeArchive('2023-01-01', 'morning', [
            ['type' => 'songs', 'title' => 'Amazing Grace'],
        ]);

        $artifact = $this->build([
            $this->statement('2023-01-01', 'morning', 'Amazing Grace'),
        ]);

        $membership = $artifact['scoring']['dimensions']['song_membership'];
        $full = $membership['by_tier']['full'];

        $this->assertSame('indeterminate', $artifact['identities'][0]['verdicts']['song_membership']);
        $this->assertSame(1, $membership['model_addressable_population'], 'the identity stays in the denominator');
        $this->assertSame(1, $full['outcomes']['indeterminate']);
        $this->assertSame(1, $full['indeterminate_from_zero_item_extraction']);
    }

    /**
     * The tier that decides whether an empty extraction is a failure at all.
     *
     * A `partial` source is curated to evidence-only retention, so holding no items is the
     * approved outcome; a `no_source` identity had nothing acquired to parse. Counting either as
     * an extraction miss reads a curation decision or an acquisition gap as a model deficiency —
     * measured on the 2026-08-16 corpus that would misattribute 131 of 146 empty extractions.
     */
    #[Test]
    public function it_separates_model_addressable_identities_from_curation_and_acquisition_gaps(): void
    {
        $this->catalogue(['Amazing Grace']);

        $this->stageService('2023-01-01', 'morning', [['type' => 'custom', 'title' => 'Welcome']], tier: 'full');
        $this->stageService('2023-01-08', 'morning', [], tier: 'partial');
        $this->stageService('2023-01-15', 'morning', [], tier: null);

        $artifact = $this->build([
            $this->statement('2023-01-01', 'morning', 'Amazing Grace'),
            $this->statement('2023-01-08', 'morning', 'Amazing Grace'),
            $this->statement('2023-01-15', 'morning', 'Amazing Grace'),
        ]);

        $membership = $artifact['scoring']['dimensions']['song_membership'];

        $this->assertSame(1, $membership['model_addressable_population']);
        $this->assertSame(3, $membership['population'], 'the other two are reported, not discarded');
        $this->assertSame(1, $membership['by_tier']['partial']['population']);
        $this->assertSame(1, $membership['by_tier']['no_source']['population']);

        $tiers = array_column(array_column($artifact['identities'], 'staged'), 'curation_tier');
        $this->assertSame(['full', 'partial', 'no_source'], $tiers);
    }

    #[Test]
    public function the_scored_population_counts_only_identities_the_evidence_reaches(): void
    {
        $this->catalogue(['Amazing Grace']);

        $this->stageService('2023-01-01', 'morning', [['type' => 'songs', 'title' => 'Amazing Grace']]);
        $this->stageService('2020-06-07', 'evening', [['type' => 'songs', 'title' => 'Amazing Grace']]);

        $this->writeArchive('2023-01-01', 'morning', [['type' => 'songs', 'title' => 'Amazing Grace']]);

        $artifact = $this->build([$this->statement('2023-01-01', 'morning', 'Amazing Grace')]);

        $dimensions = $artifact['scoring']['dimensions'];

        // Two staged identities, but only one that either source covers.
        $this->assertSame(2, $artifact['counts']['staged_identities']);
        $this->assertSame(1, $dimensions['song_membership']['population']);
        $this->assertSame(1, $dimensions['song_count']['population']);
        $this->assertSame('hymn_workbook', $dimensions['song_membership']['evidence']);
        $this->assertSame('openlp', $dimensions['song_order']['evidence']);

        foreach ($dimensions as $dimension) {
            foreach ($dimension['by_tier'] as $tier) {
                $this->assertSame(
                    $tier['population'],
                    array_sum($tier['outcomes']),
                    'every scored identity lands in exactly one outcome',
                );
            }
        }
    }

    /**
     * A service may carry several current email source records. Where they disagree about whether a
     * complete running order was available, there is no single expectation to measure an extraction
     * against — and picking one by database row order would decide, arbitrarily, whether the
     * identity is scored at all. Measured on the rehearsal corpus, 7 services are in this state.
     */
    #[Test]
    public function it_refuses_to_tier_a_service_whose_current_sources_disagree_on_scope(): void
    {
        $this->catalogue(['Amazing Grace']);

        $service = ChurchService::factory()->create([
            'date' => '2023-01-01',
            'service' => 'morning',
            'source' => 'email',
        ]);

        foreach ([true, false] as $complete) {
            ChurchServiceSourceRecord::factory()->create([
                'church_service_id' => $service->id,
                'source' => ChurchServiceSource::Email,
                'payload_complete' => $complete,
            ]);
        }

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'title' => 'Amazing Grace',
            'source_title' => null,
            'openlp_search_title' => null,
            'source' => ChurchServiceItemSource::Email->value,
        ]);

        $artifact = $this->build([$this->statement('2023-01-01', 'morning', 'Amazing Grace')]);
        $membership = $artifact['scoring']['dimensions']['song_membership'];

        $this->assertSame('mixed', $artifact['identities'][0]['staged']['curation_tier']);
        $this->assertSame(0, $membership['model_addressable_population'], 'a mixed service is never scored');
        $this->assertSame(1, $membership['by_tier']['mixed']['population'], 'but it is still reported');
    }

    #[Test]
    public function it_refuses_to_overwrite_an_existing_artifact(): void
    {
        $this->catalogue(['Amazing Grace']);
        $this->stageService('2023-01-01', 'morning', [['type' => 'songs', 'title' => 'Amazing Grace']]);

        $output = "{$this->root}/ground-truth.json";
        file_put_contents($output, 'already here');

        $this->artisan('service-tracking:build-item-ground-truth', [
            '--hymn-reconciliation' => $this->writeHymnArtifact([]),
            '--openlp-manifest' => $this->writeManifest(),
            '--openlp-root' => "{$this->root}/raw",
            '--output' => $output,
        ])
            ->expectsOutputToContain('Refusing to overwrite')
            ->assertExitCode(1);

        $this->assertSame('already here', file_get_contents($output));
    }

    /**
     * @param  list<array<string, mixed>>  $statements
     * @return array<string, mixed>
     */
    private function build(array $statements): array
    {
        $output = "{$this->root}/ground-truth-".bin2hex(random_bytes(4)).'.json';

        $this->artisan('service-tracking:build-item-ground-truth', [
            '--hymn-reconciliation' => $this->writeHymnArtifact($statements),
            '--openlp-manifest' => $this->writeManifest(),
            '--openlp-root' => "{$this->root}/raw",
            '--output' => $output,
        ])->assertExitCode(0);

        $contents = file_get_contents($output);
        self::assertIsString($contents);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param  list<string>  $titles
     * @return array<string, int>
     */
    private function catalogue(array $titles): array
    {
        $songs = [];

        foreach ($titles as $title) {
            $songs[$title] = (int) Song::factory()->create([
                'title' => $title,
                'canonical_key' => Song::canonicalizeKey($title),
                'alternate_title' => null,
            ])->id;
        }

        return $songs;
    }

    /**
     * @param  list<array{type: string, title: string}>  $items
     */
    private function stageService(
        string $date,
        string $service,
        array $items,
        ChurchServiceItemSource $source = ChurchServiceItemSource::Email,
        ?string $tier = 'full',
    ): void {
        $churchService = ChurchService::factory()->create([
            'date' => $date,
            'service' => $service,
            'source' => 'email',
        ]);

        /**
         * The curation tier is read from the current email source record's `payload_complete`.
         * A null tier stages no record at all, which is the `no_source` case.
         */
        if ($tier !== null) {
            ChurchServiceSourceRecord::factory()->create([
                'church_service_id' => $churchService->id,
                'source' => ChurchServiceSource::Email,
                'payload_complete' => $tier === 'full',
            ]);
        }

        foreach ($items as $position => $item) {
            ChurchServiceItem::factory()->create([
                'church_service_id' => $churchService->id,
                'position' => $position + 1,
                'type' => $item['type'],
                'title' => $item['title'],
                'source_title' => null,
                'openlp_search_title' => null,
                'source' => $source->value,
            ]);
        }
    }

    /**
     * @param  list<array{type: string, title: string}>  $items
     */
    private function writeArchive(string $date, string $service, array $items): void
    {
        $slot = $service === 'morning' ? 'AM' : 'PM';
        $name = "{$date} {$slot}";
        $payload = [['openlp_core' => ['lite-service' => false]]];

        foreach ($items as $item) {
            $payload[] = ['serviceitem' => ['header' => [
                'name' => $item['type'],
                'plugin' => $item['type'],
                'title' => $item['title'],
                'data' => $item['type'] === 'songs' ? ['title' => $item['title']] : '',
                'footer' => [$item['title']],
            ]]];
        }

        $zip = new ZipArchive;
        self::assertTrue($zip->open("{$this->root}/raw/{$name}.osz", ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $zip->addFromString("{$name}.osj", json_encode($payload, JSON_THROW_ON_ERROR));
        $zip->close();
    }

    /**
     * A manifest over whatever archives the test wrote, so the curation authority the builder
     * insists on is real rather than stubbed.
     */
    private function writeManifest(): string
    {
        $entries = [];

        foreach (glob("{$this->root}/raw/*.osz") ?: [] as $path) {
            $name = basename($path);
            preg_match('/^(\d{4}-\d{2}-\d{2}) (AM|PM)\.osz$/', $name, $matches);
            $hash = hash_file('sha256', $path);
            $size = filesize($path);
            self::assertIsString($hash);
            self::assertIsInt($size);

            $entries[] = [
                'item_key' => 'openlp:'.$name,
                'source_kind' => 'openlp',
                'relative_path' => $name,
                'sha256' => $hash,
                'byte_size' => $size,
                'disposition' => 'include',
                'duplicate_target_hash' => null,
                'logical_upload_filename' => $name,
                'resolved_date' => $matches[1],
                'resolved_service' => $matches[2] === 'AM' ? 'morning' : 'evening',
                'alias_reason' => null,
                'exclusion_reason' => null,
                'parse_decision' => 'strict',
                'concatenation_decision' => 'none',
                'expected_item_count' => $this->archiveItemCount($path),
                'decided_by' => 'curator@crockenhill.test',
                'decided_at' => '2026-08-16T09:00:00+00:00',
                'decision_rule_version' => 'openlp-filename-pattern-v1',
            ];
        }

        $manifest = [
            'format' => 'crockenhill-openlp-curation',
            'version' => 3,
            'batch_key' => 'openlp-ground-truth-test',
            'expected_counts' => [
                'raw' => count($entries),
                'include' => count($entries),
                'duplicate-of' => 0,
                'exclude' => 0,
                'aliases' => 0,
            ],
            'entries' => $entries,
        ];

        $path = "{$this->root}/openlp-manifest.json";
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    private function archiveItemCount(string $path): int
    {
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path) === true);
        $osj = null;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name) && str_ends_with($name, '.osj')) {
                $osj = $zip->getFromIndex($index);
            }
        }

        $zip->close();
        self::assertIsString($osj);

        /** @var array<int, array<string, mixed>> $decoded */
        $decoded = json_decode($osj, true, flags: JSON_THROW_ON_ERROR);

        return count(array_filter($decoded, static fn (array $entry): bool => array_key_exists('serviceitem', $entry)));
    }

    /**
     * @param  list<array<string, mixed>>  $statements
     */
    private function writeHymnArtifact(array $statements): string
    {
        $path = "{$this->root}/hymn-reconciliation.json";
        file_put_contents($path, json_encode([
            'format' => 'historic-hymn-reconciliation',
            'version' => 1,
            'generated_at' => '2026-08-14T09:15:19+00:00',
            'catalogue' => ['song_count' => count($statements), 'fingerprint' => str_repeat('a', 64)],
            'statements_sha256' => str_repeat('b', 64),
            'statements' => $statements,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function statement(string $date, string $service, string $title, ?string $number = null): array
    {
        return [
            'used_on' => $date,
            'reported_service' => $service,
            'reported_number' => $number,
            'reported_title' => $title,
            'song_id' => null,
            'match_method' => 'exact',
        ];
    }
}
