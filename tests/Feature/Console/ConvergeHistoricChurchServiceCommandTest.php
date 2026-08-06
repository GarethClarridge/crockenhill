<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Data\HistoricConvergenceOperationPlan;
use App\Services\ChurchService\ChurchServiceConvergenceBundle;
use App\Services\ChurchService\ConvergeHistoricChurchService;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use DateTimeImmutable;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConvergeHistoricChurchServiceCommandTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_fails_without_reading_or_writing_when_a_bundle_is_missing(): void
    {
        $this->artisan('service-tracking:converge-historic-service', [
            'media-bundle' => storage_path('app/private/missing-bundle-a.json'),
            'convergence-bundle' => storage_path('app/private/missing-bundle-b.json'),
        ])
            ->expectsOutputToContain('Bundle file is missing:')
            ->assertFailed();
    }

    /**
     * This is the G8 operation itself, so the guard has to stop it before
     * `executeBatch()` rather than trust the operator to have read the plan.
     */
    #[Test]
    public function an_unapproved_production_apply_never_reaches_execution(): void
    {
        $converge = $this->stubbedConvergence();
        $converge->expects($this->never())->method('executeBatch');
        $converge->expects($this->never())->method('execute');

        Config::set('church.historic_corpus.production_import_approval', null);
        $this->app['env'] = 'production';

        $this->artisan('service-tracking:converge-historic-service', $this->applyArguments())
            ->expectsOutputToContain('no approved G8 import operation is recorded')
            ->assertFailed();
    }

    /**
     * The preflight must stay usable in production, because revalidating the
     * production-window prerequisites is what G8 *is*. A guard that blocked the
     * dry run too would make the gate unreachable.
     */
    #[Test]
    public function the_production_preflight_is_not_blocked(): void
    {
        $this->stubbedConvergence();

        Config::set('church.historic_corpus.production_import_approval', null);
        $this->app['env'] = 'production';

        $arguments = $this->applyArguments();
        unset($arguments['--apply'], $arguments['--plan-hash']);

        $this->artisan('service-tracking:converge-historic-service', $arguments)
            ->expectsOutputToContain('bundle pair is valid')
            ->assertSuccessful();
    }

    /**
     * The command's own preflight and plan-hash binding are covered elsewhere;
     * here the bundles only have to get past validation so the guard is reached.
     */
    private function stubbedConvergence(): ConvergeHistoricChurchService
    {
        foreach ([HistoricProcessingResultBundle::class, ChurchServiceConvergenceBundle::class] as $bundleClass) {
            $bundles = $this->createMock($bundleClass);
            $bundles->method('validate')->willReturnArgument(0);
            $this->app->instance($bundleClass, $bundles);
        }

        $converge = $this->createMock(ConvergeHistoricChurchService::class);
        $converge->method('prepareBatch')->willReturn($this->plan());
        $this->app->instance(ConvergeHistoricChurchService::class, $converge);

        return $converge;
    }

    /** @return array<string, mixed> */
    private function applyArguments(): array
    {
        return [
            'media-bundle' => $this->bundleFile(),
            'convergence-bundle' => $this->bundleFile(),
            '--operation-id' => 'rehearsal-1',
            '--expires-at' => '2099-01-01T00:00:00+00:00',
            '--apply' => true,
            '--plan-hash' => str_repeat('e', 64),
        ];
    }

    private function plan(): HistoricConvergenceOperationPlan
    {
        return new HistoricConvergenceOperationPlan(
            operationId: 'rehearsal-1',
            planHash: str_repeat('e', 64),
            contentHash: str_repeat('f', 64),
            batchHash: str_repeat('b', 64),
            mediaBundleHash: str_repeat('a', 64),
            convergenceBundleHash: str_repeat('c', 64),
            processingFingerprint: ['version' => 1],
            storageIdentity: [],
            expiresAt: new DateTimeImmutable('2099-01-01T00:00:00+00:00'),
            services: [],
            summary: [],
        );
    }

    private function bundleFile(): string
    {
        $path = sys_get_temp_dir().'/converge_bundle_'.str_replace('.', '', uniqid('', true)).'.json';
        file_put_contents($path, json_encode(['services' => []], JSON_THROW_ON_ERROR));
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
