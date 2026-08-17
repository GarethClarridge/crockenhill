<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosParserEvaluationArm;
use App\Services\Email\OosEmailParserService;
use App\Services\Email\OosParserArmRunner;
use App\Services\Email\OosParserEvaluationTelemetry;
use App\Services\Import\HistoricImportProductionGuard;
use Illuminate\Database\DatabaseManager;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OosParserArmRunnerTest extends TestCase
{
    #[Test]
    public function it_refuses_before_a_model_call_when_the_database_resolves_the_production_anchor(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $guard = Mockery::mock(HistoricImportProductionGuard::class);
        $parser = Mockery::mock(OosEmailParserService::class);
        $guard->shouldReceive('guardsCurrentEnvironment')->once()->andReturnTrue();
        $parser->shouldNotReceive('parse');

        $runner = new OosParserArmRunner($database, $guard, $parser, new OosParserEvaluationTelemetry);

        try {
            $runner->run(OosParserEvaluationArm::fromName('baseline-nano-none'), [], 'manifest-hash', '/manifest.json');
            $this->fail('The production anchor must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('production database anchor', $exception->getMessage());
        }
    }
}
