<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ChurchService;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Services\ChurchService\ChurchServiceConvergenceBundle;
use App\Services\ChurchService\ChurchServiceConvergenceBundleImporter;
use App\Services\ChurchService\ConvergeHistoricChurchService;
use App\Services\HistoricMedia\HistoricProcessingResultAssetTransfer;
use App\Services\HistoricMedia\HistoricProcessingResultBundle;
use App\Services\HistoricMedia\HistoricProcessingResultBundleImporter;
use App\Services\HistoricMedia\HistoricProcessingResultInventory;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ConvergeHistoricChurchServiceTest extends TestCase
{
    #[Test]
    public function it_rejects_bundles_that_do_not_share_the_exact_media_identity_before_preflight(): void
    {
        $mediaBundles = $this->createMock(HistoricProcessingResultBundle::class);
        $convergenceBundles = $this->createMock(ChurchServiceConvergenceBundle::class);
        $mediaImporter = $this->createMock(HistoricProcessingResultBundleImporter::class);
        $mediaBundles->method('validate')->willReturnArgument(0);
        $convergenceBundles->method('validate')->willReturnArgument(0);
        $mediaImporter->expects($this->never())->method('prepareService');
        $service = new ConvergeHistoricChurchService(
            $mediaBundles,
            $convergenceBundles,
            $mediaImporter,
            $this->createMock(ChurchServiceConvergenceBundleImporter::class),
            $this->createMock(HistoricProcessingResultAssetTransfer::class),
            $this->createMock(HistoricProcessingResultInventory::class),
            $this->createMock(IngestChurchServiceSourceRevision::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not describe the same service result');

        $service->execute($this->mediaBundle(), $this->convergenceBundle());
    }

    /** @return array<string, mixed> */
    private function mediaBundle(): array
    {
        return [
            'bundle_hash' => str_repeat('a', 64),
            'batch_hash' => str_repeat('b', 64),
            'processing_fingerprint' => ['version' => 1],
            'services' => [[
                'date' => '2026-08-02',
                'service' => 'morning',
                'evidence_set_hash' => str_repeat('c', 64),
                'pre_review_hash' => str_repeat('d', 64),
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function convergenceBundle(): array
    {
        return [
            'media_bundle_hash' => str_repeat('0', 64),
            'batch_hash' => str_repeat('b', 64),
            'processing_fingerprint' => ['version' => 1],
            'services' => [[
                'date' => '2026-08-02',
                'service' => 'morning',
                'evidence_set_hash' => str_repeat('c', 64),
                'pre_review_hash' => str_repeat('d', 64),
            ]],
        ];
    }
}
