<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Data\OosParserEvaluationArm;
use App\Services\Email\OosEmailParserService;
use App\Services\Email\OosParserArmRunner;
use App\Services\Email\OosParserEvaluationTelemetry;
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
            $parser,
            new OosParserEvaluationTelemetry,
        );

        try {
            $runner->run(OosParserEvaluationArm::fromName('baseline-nano-none'), [], 'manifest-hash', '/manifest.json');
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
            $runner->run(OosParserEvaluationArm::fromName('baseline-nano-none'), $this->entries(), 'manifest-hash', '/manifest.json');
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
        );

        $this->assertSame(3, $report['version']);
        $this->assertSame(0, $report['rehearsal_certification']['church_services']);

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
