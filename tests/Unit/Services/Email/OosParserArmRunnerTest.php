<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Data\OosParserEvaluationArm;
use App\Enums\OosEmailContentScope;
use App\Enums\SermonService;
use App\Services\Email\OosEmailParserService;
use App\Services\Email\OosParserArmRunner;
use App\Services\Email\OosParserEvaluationTelemetry;
use App\Services\Email\OosParserSurfaceFingerprint;
use App\Services\Import\HistoricImportProductionGuard;
use App\Services\Import\RehearsalDatabaseProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Mockery;
use Mockery\MockInterface;
use OpenAI\Responses\Chat\CreateResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OosParserArmRunnerTest extends TestCase
{
    private const Database = 'crockenhill_rehearsal';

    #[Test]
    public function it_refuses_before_a_model_call_when_the_database_resolves_the_production_anchor(): void
    {
        $guard = Mockery::mock(HistoricImportProductionGuard::class);
        $parser = Mockery::mock(OosEmailParserService::class);
        $guard->shouldReceive('guardsCurrentEnvironment')->once()->andReturnTrue();
        $parser->shouldNotReceive('parse');

        $database = Mockery::mock(DatabaseManager::class);
        $runner = new OosParserArmRunner(
            $database,
            $guard,
            new RehearsalDatabaseProvisioner($database, $guard),
            new OosParserSurfaceFingerprint,
            $parser,
            new OosParserEvaluationTelemetry,
        );

        try {
            $runner->run(OosParserEvaluationArm::fromName('baseline-nano-none'), [], 'manifest-hash', '/manifest.json', $this->priceSnapshot());
            $this->fail('The production anchor must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('production database anchor', $exception->getMessage());
        }
    }

    /**
     * The parse is not a pure function of the email: it reaches `church_services` through
     * `ExistingEmailImportLookup`, so a database somebody had already staged into would change hold
     * reasons and routing for reasons that have nothing to do with the model.
     */
    #[Test]
    public function it_refuses_before_a_model_call_when_the_rehearsal_database_is_not_clean(): void
    {
        $parser = Mockery::mock(OosEmailParserService::class);
        $parser->shouldNotReceive('parse');

        $runner = $this->runner($parser, new OosParserEvaluationTelemetry, ['church_services' => 4]);

        try {
            $runner->run(OosParserEvaluationArm::fromName('baseline-nano-none'), $this->entries(), 'manifest-hash', '/manifest.json', $this->priceSnapshot());
            $this->fail('A populated rehearsal database must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('is not clean', $exception->getMessage());
            $this->assertStringContainsString('church_services', $exception->getMessage());
        }
    }

    #[Test]
    public function it_exports_the_canary_and_replicate_telemetry_beside_the_corpus(): void
    {
        $telemetry = new OosParserEvaluationTelemetry;
        $parser = Mockery::mock(OosEmailParserService::class);
        $parser->shouldReceive('parse')->andReturnUsing(function () use ($telemetry): OosEmailParseResult {
            $telemetry->record(CreateResponse::fake(['model' => 'gpt-5.4-nano']), 'extract', 1, microtime(true));

            return new OosEmailParseResult(null, null, [], 0.0, true, false, []);
        });

        $report = $this->runner($parser, $telemetry)->run(
            OosParserEvaluationArm::fromName('baseline-nano-none'),
            $this->entries(),
            'manifest-hash',
            '/manifest.json',
            $this->priceSnapshot(),
        );

        $this->assertSame(4, $report['version']);
        $this->assertSame(0, $report['rehearsal_certification']['church_services']);

        // The manifest proves what code and settings ran, and agrees with the projection it describes.
        $manifest = $report['run_manifest'];
        $this->assertSame('baseline-nano-none', $manifest['arm']);
        $this->assertSame('gpt-5.4-nano', $manifest['model']);
        $this->assertSame($report['source_key_list_hash'], $manifest['inputs']['source_key_list_hash']);
        $this->assertSame(0.75, $manifest['thresholds']['review']);
        $this->assertSame(6000, $manifest['ceilings']['max_completion_tokens']);
        $this->assertNotEmpty($manifest['parser_surface']['files']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $manifest['parser_surface']['hash']);
        $this->assertSame('oos-parser-arm-baseline-nano-none.log', $report['log_file']);

        // Two distinct canary shapes over two entries, and each of the two is parsed twice for stability.
        $this->assertCount(2, $report['canary']['source_keys']);
        $this->assertCount(2, $report['canary']['telemetry']);
        $this->assertCount(4, $report['stability']['telemetry']);
        $this->assertSame([1, 2, 1, 2], array_column($report['stability']['telemetry'], 'replicate'));

        // Every auxiliary call carries the usage a cost figure needs, and none of it leaked into a source.
        foreach ([...$report['canary']['telemetry'], ...$report['stability']['telemetry']] as $call) {
            $this->assertFalse($call['usage_missing']);
            $this->assertIsInt($call['usage']['input_tokens']);
        }

        foreach ($report['raw_results'] as $source) {
            $this->assertCount(1, $source['telemetry']);
        }
    }

    /**
     * The regression test for the defect that inflated a real baseline's self-disagreement to
     * 100% on its first run: `confidence` is a continuous float and `validation_reasons` is
     * model-generated prose, neither of which two independent calls reproduce verbatim even when
     * the extraction itself — service, date, items, content scope — is identical. Comparing on
     * `raw_result_hash` counted that as disagreement; comparing on the narrower stability
     * signature must not.
     */
    #[Test]
    public function it_does_not_count_confidence_or_validation_reason_variance_as_self_disagreement(): void
    {
        $telemetry = new OosParserEvaluationTelemetry;
        $parser = Mockery::mock(OosEmailParserService::class);
        $call = 0;

        $parser->shouldReceive('parse')->andReturnUsing(function () use ($telemetry, &$call): OosEmailParseResult {
            $call++;
            $telemetry->record(CreateResponse::fake(['model' => 'gpt-5.4-nano']), 'extract', 1, microtime(true));

            $plan = new OosEmailServicePlan(
                service: SermonService::Morning,
                date: '2023-01-01',
                items: [['position' => 1, 'type' => 'song', 'title' => 'Amazing Grace', 'source_title' => 'Amazing Grace', 'openlp_search_title' => null, 'metadata' => null]],
                // Varies every call, the way a model's self-reported confidence genuinely does.
                confidence: 0.80 + ($call * 0.001),
                needsReview: false,
                shouldImport: true,
                validationReasons: ["Call {$call}: confidence within tolerance"],
                contentScope: OosEmailContentScope::Full,
            );

            return new OosEmailParseResult(
                date: '2023-01-01',
                service: SermonService::Morning,
                items: $plan->items,
                confidenceScore: $plan->confidence,
                needsReview: false,
                shouldImport: true,
                importMetadata: [],
                servicePlans: [$plan],
            );
        });

        $report = $this->runner($parser, $telemetry)->run(
            OosParserEvaluationArm::fromName('baseline-nano-none'),
            $this->entries(),
            'manifest-hash',
            '/manifest.json',
            $this->priceSnapshot(),
        );

        $this->assertSame(0, $report['stability']['self_disagreements']);
        $this->assertEquals(0.0, $report['stability']['rate']);
    }

    /**
     * The order the model happened to emit two service plans in is not an extraction claim —
     * `plan_key` already identifies which service each plan is for — and the between-arm comparison
     * has always keyed and sorted them. Until both comparisons shared one definition, a replicate
     * pair that merely reordered its plans counted as self-disagreement here while not counting as
     * discordance there, inflating the figure that gates the whole comparison. 142 of the 554
     * banked sources emit their plans non-lexicographically, so this was reachable, not theoretical.
     */
    #[Test]
    public function it_does_not_count_a_reordered_service_plan_list_as_self_disagreement(): void
    {
        $telemetry = new OosParserEvaluationTelemetry;
        $parser = Mockery::mock(OosEmailParserService::class);
        $call = 0;

        $parser->shouldReceive('parse')->andReturnUsing(function () use ($telemetry, &$call): OosEmailParseResult {
            $call++;
            $telemetry->record(CreateResponse::fake(['model' => 'gpt-5.4-nano']), 'extract', 1, microtime(true));

            $morning = $this->servicePlan(SermonService::Morning, 'Amazing Grace');
            $evening = $this->servicePlan(SermonService::Evening, 'Abide With Me');

            // The two replicates of a pair are consecutive calls, so alternating puts the same two
            // plans in front of the comparison in opposite orders.
            $plans = $call % 2 === 1 ? [$morning, $evening] : [$evening, $morning];

            return new OosEmailParseResult(
                date: '2023-01-01',
                service: SermonService::Morning,
                items: $morning->items,
                confidenceScore: 0.9,
                needsReview: false,
                shouldImport: true,
                importMetadata: [],
                servicePlans: $plans,
            );
        });

        $report = $this->runner($parser, $telemetry)->run(
            OosParserEvaluationArm::fromName('baseline-nano-none'),
            $this->entries(),
            'manifest-hash',
            '/manifest.json',
            $this->priceSnapshot(),
        );

        $this->assertSame(0, $report['stability']['self_disagreements']);
        $this->assertSame([], $report['stability']['disagreements']);

        // The sample is deterministic, so recording which sources it drew is what lets one
        // diagnostic be compared with an earlier one rather than merely reported beside it.
        $this->assertEqualsCanonicalizing(['email-001', 'email-002'], $report['stability']['sample_source_keys']);
    }

    /**
     * The other half of the same guarantee: narrowing the comparison must not have narrowed it past
     * the extraction itself, and a disagreement has to say *what* moved. A rate alone left §6.2
     * step 2 unable to tell genuine model non-determinism from a still-too-strict signature.
     */
    #[Test]
    public function it_counts_a_changed_item_title_and_attributes_it_to_the_title_field_group(): void
    {
        $telemetry = new OosParserEvaluationTelemetry;
        $parser = Mockery::mock(OosEmailParserService::class);
        $call = 0;

        $parser->shouldReceive('parse')->andReturnUsing(function () use ($telemetry, &$call): OosEmailParseResult {
            $call++;
            $telemetry->record(CreateResponse::fake(['model' => 'gpt-5.4-nano']), 'extract', 1, microtime(true));

            $plan = $this->servicePlan(SermonService::Morning, $call % 2 === 1 ? 'Amazing Grace' : 'Amazing Grace (My Chains Are Gone)');

            return new OosEmailParseResult(
                date: '2023-01-01',
                service: SermonService::Morning,
                items: $plan->items,
                confidenceScore: 0.9,
                needsReview: false,
                shouldImport: true,
                importMetadata: [],
                servicePlans: [$plan],
            );
        });

        $report = $this->runner($parser, $telemetry)->run(
            OosParserEvaluationArm::fromName('baseline-nano-none'),
            $this->entries(),
            'manifest-hash',
            '/manifest.json',
            $this->priceSnapshot(),
        );

        $stability = $report['stability'];
        $this->assertSame(2, $stability['sample_size']);
        $this->assertSame(2, $stability['self_disagreements']);
        $this->assertSame(1.0, $stability['rate']);

        // The decomposition names the group, so an instability figure is attributable rather than bare.
        $this->assertSame(2, $stability['field_decomposition']['titles']);
        $this->assertSame(0, $stability['field_decomposition']['item_structure']);
        $this->assertSame(0, $stability['field_decomposition']['plan_keys']);
        $this->assertSame(0, $stability['field_decomposition']['routing_category']);

        // And the retained diff carries the two concrete titles a human has to read to adjudicate it.
        // Both sampled sources disagree; the sample's order is a hash artefact, not a claim.
        $this->assertEqualsCanonicalizing(['email-001', 'email-002'], array_column($stability['disagreements'], 'source_key'));

        $difference = $stability['disagreements'][0];
        $this->assertSame(['titles'], $difference['extraction']['groups_that_differ']);
        $changed = $difference['extraction']['plans']['morning:2023-01-01']['titles']['changed_positions'][0];
        $this->assertSame('Amazing Grace', $changed['first']['title']);
        $this->assertSame('Amazing Grace (My Chains Are Gone)', $changed['second']['title']);
    }

    /**
     * The whole n-scaling design rests on this: the sample is a prefix of one total order over the
     * corpus, so drawing more sources *extends* it rather than redrawing it. If it redrew, a larger
     * run could not be compared with the banked 30-source arms and would need its own baseline.
     */
    #[Test]
    public function it_draws_a_nested_stability_sample_so_a_larger_run_stays_comparable_to_a_smaller_one(): void
    {
        $entries = $this->entriesFor(6);

        $small = $this->stableRun($entries, 2);
        $large = $this->stableRun($entries, 5);

        $this->assertCount(2, $small['sample_source_keys']);
        $this->assertCount(5, $large['sample_source_keys']);
        $this->assertSame($small['sample_source_keys'], array_slice($large['sample_source_keys'], 0, 2));
    }

    /**
     * A corpus smaller than the requested sample is otherwise indistinguishable in the artifact from
     * a deliberately smaller run — and "the corpus ran out" bounds the power the design could ever
     * have had, which is a fact about the evidence rather than an operator's choice.
     */
    #[Test]
    public function it_records_the_requested_sample_beside_the_one_the_corpus_could_supply(): void
    {
        $stability = $this->stableRun($this->entries(), 10);

        $this->assertSame(10, $stability['requested_sample_size']);
        $this->assertSame(2, $stability['sample_size']);
        $this->assertSame(0.0, $stability['rate']);
    }

    #[Test]
    public function it_refuses_a_stability_sample_that_draws_no_sources(): void
    {
        $parser = Mockery::mock(OosEmailParserService::class);
        $parser->shouldNotReceive('parse');

        try {
            $this->runner($parser, new OosParserEvaluationTelemetry)->run(
                OosParserEvaluationArm::fromName('nano-low'),
                $this->entries(),
                'manifest-hash',
                '/manifest.json',
                $this->priceSnapshot(),
                stabilitySampleSize: 0,
            );
            $this->fail('A sample of no sources must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('at least one source', $exception->getMessage());
        }
    }

    /**
     * The retained diffs are what a human reads to tell a substantive disagreement from a
     * bookkeeping one. At the previous cap of 10 that reading was a sample rather than a census, so
     * the 2026-08-18 diagnostic could say Luna's provenance group moved in 17 of 19 pairs but not
     * whether those pairs were provenance-only. An arm anywhere near passing must retain all of them.
     */
    #[Test]
    public function it_retains_every_disagreeing_pair_past_the_old_cap_and_says_so(): void
    {
        $stability = $this->stableRun($this->entriesFor(12), 12, unstable: true);

        $this->assertSame(12, $stability['self_disagreements']);
        $this->assertCount(12, $stability['disagreements']);
        $this->assertGreaterThanOrEqual(12, $stability['retained_difference_limit']);
    }

    /**
     * Runs one arm over a mocked parser and returns its stability block.
     *
     * `$unstable` alternates the item title on every call. The two replicates of a pair are always
     * consecutive calls, so opposite parity — and therefore a disagreement — whatever offset the
     * canary parses leave the counter at.
     *
     * @param  list<OosArchiveEntry>  $entries
     * @return array<string, mixed>
     */
    private function stableRun(array $entries, int $sampleSize, bool $unstable = false): array
    {
        $telemetry = new OosParserEvaluationTelemetry;
        $parser = Mockery::mock(OosEmailParserService::class);
        $call = 0;

        $parser->shouldReceive('parse')->andReturnUsing(function () use ($telemetry, &$call, $unstable): OosEmailParseResult {
            $call++;
            $telemetry->record(CreateResponse::fake(['model' => 'gpt-5.4-nano']), 'extract', 1, microtime(true));

            $plan = $this->servicePlan(SermonService::Morning, $unstable && $call % 2 === 0 ? 'Be Thou My Vision' : 'Amazing Grace');

            return new OosEmailParseResult(
                date: '2023-01-01',
                service: SermonService::Morning,
                items: $plan->items,
                confidenceScore: 0.9,
                needsReview: false,
                shouldImport: true,
                importMetadata: [],
                servicePlans: [$plan],
            );
        });

        $report = $this->runner($parser, $telemetry)->run(
            OosParserEvaluationArm::fromName('nano-low'),
            $entries,
            'manifest-hash',
            '/manifest.json',
            $this->priceSnapshot(),
            stabilitySampleSize: $sampleSize,
        );

        /** @var array<string, mixed> $stability */
        $stability = $report['stability'];

        return $stability;
    }

    /**
     * A corpus of `$count` full-scope sources. The first names both services so it can serve as the
     * multi-service canary, and every body has a distinct length so the shortest and longest
     * canaries are picked deterministically rather than by sort tie-breaking.
     *
     * @return list<OosArchiveEntry>
     */
    private function entriesFor(int $count): array
    {
        $entries = [$this->entry(0, 'email-001', "Morning service\nEvening service")];

        for ($index = 1; $index < $count; $index++) {
            $entries[] = $this->entry($index, sprintf('email-%03d', $index + 1), 'Morning worship'.str_repeat('!', $index));
        }

        return $entries;
    }

    private function servicePlan(SermonService $service, string $title): OosEmailServicePlan
    {
        return new OosEmailServicePlan(
            service: $service,
            date: '2023-01-01',
            items: [['position' => 1, 'type' => 'song', 'title' => $title, 'source_title' => $title, 'openlp_search_title' => null, 'metadata' => null]],
            confidence: 0.9,
            needsReview: false,
            shouldImport: true,
            contentScope: OosEmailContentScope::Full,
        );
    }

    /** @return array<string, mixed> */
    private function priceSnapshot(): array
    {
        return ['taken_at' => '2026-08-17', 'models' => ['gpt-5.4-nano' => ['input' => 0.20, 'output' => 1.25]]];
    }

    /** @param array<string, int> $counts */
    private function runner(MockInterface $parser, OosParserEvaluationTelemetry $telemetry, array $counts = []): OosParserArmRunner
    {
        config(['database.connections.'.RehearsalDatabaseProvisioner::Connection.'.database' => self::Database]);

        $guard = Mockery::mock(HistoricImportProductionGuard::class);
        $guard->shouldReceive('guardsCurrentEnvironment')->andReturnFalse();

        $schema = Mockery::mock(SchemaBuilder::class);
        $schema->shouldReceive('hasTable')->with('inbound_emails')->andReturnTrue();

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')->andReturn(self::Database);
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $connection->shouldReceive('table')->andReturnUsing(function (string $table) use ($counts): QueryBuilder {
            $query = Mockery::mock(QueryBuilder::class);
            $query->shouldReceive('count')->andReturn($counts[$table] ?? 0);

            return $query;
        });

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('purge');
        $database->shouldReceive('connection')->andReturn($connection);

        /** @var OosEmailParserService $parser */
        return new OosParserArmRunner(
            $database,
            $guard,
            new RehearsalDatabaseProvisioner($database, $guard),
            new OosParserSurfaceFingerprint,
            $parser,
            $telemetry,
        );
    }

    /**
     * Two full-scope entries: one names both services, so it is both the longest order and the
     * multi-service canary, and the other is the shortest.
     *
     * @return list<OosArchiveEntry>
     */
    private function entries(): array
    {
        return [
            $this->entry(0, 'email-001', "Morning service\nEvening service"),
            $this->entry(1, 'email-002', 'Morning worship'),
        ];
    }

    private function entry(int $index, string $key, string $body): OosArchiveEntry
    {
        return new OosArchiveEntry(
            index: $index,
            itemKey: $key,
            subject: 'Order of service',
            bodyPlain: $body,
            groundTruthDate: '2023-01-01',
            contentScope: 'full',
            servicesPresent: ['morning'],
            itemLineCounts: [],
            curation: [
                'date_decision' => 'manifest',
                'date_decision_reason' => null,
                'parse_decision' => 'include',
                'content_scope' => 'full',
                'partial_scope_reason' => null,
                'payload' => 'complete',
                'service_label' => 'morning',
                'title_override' => null,
                'supersedes' => null,
                'expected_item_count' => null,
                'decided_by' => null,
                'decided_at' => null,
                'decision_rule_version' => null,
            ],
            syntheticMessageId: "{$key}@crockenhill.local",
            sourceKey: $key,
            supersedesSourceKey: null,
            inputHash: "input-{$key}",
            syntheticReceivedAt: CarbonImmutable::parse('2023-01-01 09:00:00'),
        );
    }
}
