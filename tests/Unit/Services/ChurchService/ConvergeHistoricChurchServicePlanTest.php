<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceConvergenceImportPlan;
use App\Data\HistoricProcessingResultImportPlan;
use App\Enums\HistoricImportClassification;
use App\Models\ChurchService;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceConvergenceBundle;
use App\Services\ChurchService\ChurchServiceConvergenceBundleImporter;
use App\Services\ChurchService\ChurchServiceProposalIdentity;
use App\Services\ChurchService\ConvergeHistoricChurchService;
use App\Services\ChurchService\HistoricConvergenceLedger;
use App\Services\HistoricMedia\HistoricProcessingResultAssetTransfer;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Operation-token and preflight rules in isolation.
 *
 * The real apply path — no-write dry runs, rollback, resume, dispatch and asset
 * compensation — is covered end to end against real importers in
 * Tests\Integration\Services\ChurchService\ConvergeHistoricChurchServiceApplyTest.
 * What is left here are the cases that need a plan the real importers would
 * never produce, so they are driven through doubles on purpose.
 */
class ConvergeHistoricChurchServicePlanTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_token_binds_the_resolved_reviewer(): void
    {
        $service = $this->service();
        $reviewerA = User::factory()->create(['email' => 'reviewer-a@example.com']);
        $reviewerB = User::factory()->create(['email' => 'reviewer-b@example.com']);
        $this->withLedger(function (string $path) use ($service, $reviewerA, $reviewerB): void {
            $converger = $this->converger(
                [$this->convergencePlan($service, $reviewerA), $this->convergencePlan($service, $reviewerB)],
                $path,
            );

            $first = $converger->prepare($this->mediaBundle(), $this->convergenceBundle());
            $second = $converger->prepare($this->mediaBundle(), $this->convergenceBundle());

            self::assertNotSame($first->planHash, $second->planHash);
            self::assertNotSame($first->contentHash, $second->contentHash);
        });
    }

    #[Test]
    public function the_token_binds_the_deployment_identifier(): void
    {
        $service = $this->service();
        $this->withLedger(function (string $path) use ($service): void {
            $plan = $this->convergencePlan($service, null);
            $converger = $this->converger([$plan, $plan], $path);

            config()->set('app.release_identifier', 'release-a');
            $first = $converger->prepare($this->mediaBundle(), $this->convergenceBundle());
            config()->set('app.release_identifier', 'release-b');
            $second = $converger->prepare($this->mediaBundle(), $this->convergenceBundle());

            self::assertNotSame($first->planHash, $second->planHash);
        });
    }

    #[Test]
    public function a_blank_deployment_identifier_refuses_to_mint_a_token(): void
    {
        $service = $this->service();
        $this->withLedger(function (string $path) use ($service): void {
            $converger = $this->converger([$this->convergencePlan($service, null)], $path);
            config()->set('app.release_identifier', '  ');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('requires a configured deployment identifier');

            $converger->prepare($this->mediaBundle(), $this->convergenceBundle());
        });
    }

    /**
     * The preflight's accepted set has to be exactly the persister's. A media
     * classification the persister rejects must stop the batch before its first
     * write, not part-way through it.
     */
    #[Test]
    public function the_preflight_rejects_media_classifications_the_persister_cannot_apply(): void
    {
        $unapplicable = [
            'safe_enrichment' => new HistoricProcessingResultImportPlan(
                classification: HistoricImportClassification::SafeEnrichment->value,
                reason: 'richer',
                planHash: str_repeat('f', 64),
                bundleHash: str_repeat('a', 64),
                service: [],
                assets: [],
            ),
            'already_present' => new HistoricProcessingResultImportPlan(
                classification: HistoricImportClassification::AlreadyPresent->value,
                reason: 'present, but without a resolved run',
                planHash: str_repeat('f', 64),
                bundleHash: str_repeat('a', 64),
                service: [],
                assets: [],
                existingProcessingLogId: null,
            ),
        ];

        foreach ($unapplicable as $classification => $mediaPlan) {
            $service = $this->service();

            $this->withLedger(function (string $path) use ($service, $mediaPlan, $classification): void {
                $converger = $this->converger(
                    [$this->convergencePlan($service, null), $this->convergencePlan($service, null)],
                    $path,
                    [$mediaPlan, $mediaPlan],
                );

                try {
                    $converger->execute($this->mediaBundle(), $this->convergenceBundle());
                    self::fail("A {$classification} media plan was allowed to apply.");
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString(
                        "Historic media preflight is {$classification} for 2026-08-02|morning.",
                        $exception->getMessage(),
                    );
                }
            });

            $service->forceDelete();
        }
    }

    private function service(): ChurchService
    {
        return ChurchService::factory()->create([
            'date' => '2026-08-02',
            'service' => 'morning',
        ]);
    }

    /** @param callable(string): void $callback */
    private function withLedger(callable $callback): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crockenhill-ledger-');
        self::assertIsString($path);

        try {
            $callback($path);
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private function mediaBundle(): array
    {
        return [
            'batch_hash' => str_repeat('b', 64),
            'bundle_hash' => str_repeat('a', 64),
            'processing_fingerprint' => ['version' => 1],
            'services' => [[
                'date' => '2026-08-02',
                'service' => 'morning',
                'evidence_set_hash' => str_repeat('c', 64),
                'pre_review_hash' => str_repeat('d', 64),
                'media_graph' => ['processing_key' => 'historic-run'],
                'livestream_source_revision' => [],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function convergenceBundle(): array
    {
        return [
            'batch_hash' => str_repeat('b', 64),
            'media_bundle_hash' => str_repeat('a', 64),
            'bundle_hash' => str_repeat('e', 64),
            'processing_fingerprint' => ['version' => 1],
            'services' => [[
                'date' => '2026-08-02',
                'service' => 'morning',
                'evidence_set_hash' => str_repeat('c', 64),
                'pre_review_hash' => str_repeat('d', 64),
            ]],
        ];
    }

    private function mediaPlan(): HistoricProcessingResultImportPlan
    {
        return new HistoricProcessingResultImportPlan(
            classification: HistoricImportClassification::Create->value,
            reason: 'create',
            planHash: str_repeat('f', 64),
            bundleHash: str_repeat('a', 64),
            service: [],
            assets: [],
        );
    }

    private function convergencePlan(
        ChurchService $service,
        ?User $reviewer,
    ): ChurchServiceConvergenceImportPlan {
        return new ChurchServiceConvergenceImportPlan(
            classification: HistoricImportClassification::SafeEnrichment->value,
            reason: 'safe',
            planHash: str_repeat('0', 64),
            bundleHash: str_repeat('e', 64),
            churchService: $service,
            reviewer: $reviewer,
            servicePayload: [],
        );
    }

    /**
     * @param  list<ChurchServiceConvergenceImportPlan>  $convergencePlans
     * @param  list<HistoricProcessingResultImportPlan>|null  $mediaPlans
     */
    private function converger(
        array $convergencePlans,
        string $ledgerPath,
        ?array $mediaPlans = null,
    ): ConvergeHistoricChurchService {
        $mediaBundles = $this->createMock(HistoricProcessingResultBundle::class);
        $mediaBundles->method('validate')->willReturnArgument(0);
        $convergenceBundles = $this->createMock(ChurchServiceConvergenceBundle::class);
        $convergenceBundles->method('validate')->willReturnArgument(0);
        $mediaImporter = $this->createMock(HistoricProcessingResultBundleImporter::class);

        if ($mediaPlans === null) {
            $mediaImporter->method('prepareService')->willReturn($this->mediaPlan());
        } else {
            $mediaImporter->method('prepareService')->willReturnOnConsecutiveCalls(...$mediaPlans);
        }

        $mediaImporter->expects($this->never())->method('persistPreparedService');
        $convergenceImporter = $this->createMock(ChurchServiceConvergenceBundleImporter::class);
        $convergenceImporter->method('prepareServiceForHistoricImport')->willReturnOnConsecutiveCalls(...$convergencePlans);
        $convergenceImporter->expects($this->never())->method('persistPreparedService');
        $assets = $this->createMock(HistoricProcessingResultAssetTransfer::class);
        $assets->method('storageIdentity')->willReturn([
            'staging' => ['name' => 'staging'],
            'production' => ['name' => 'production'],
        ]);

        return new ConvergeHistoricChurchService(
            $mediaBundles,
            $convergenceBundles,
            $mediaImporter,
            $convergenceImporter,
            $assets,
            $this->createMock(HistoricProcessingResultInventory::class),
            $this->createMock(IngestChurchServiceSourceRevision::class),
            new HistoricConvergenceLedger($ledgerPath),
            $this->createMock(ChurchServiceProposalIdentity::class),
        );
    }
}
