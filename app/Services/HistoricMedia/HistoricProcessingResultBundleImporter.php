<?php

declare(strict_types=1);

namespace App\Services\HistoricMedia;

use App\Data\HistoricProcessingResultImportPlan;
use App\Models\MediaProcessingLog;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HistoricProcessingResultBundleImporter
{
    public function __construct(
        private readonly HistoricProcessingResultBundle $bundles,
        private readonly HistoricProcessingResultAssetTransfer $assets,
        private readonly HistoricProcessingResultInventory $inventory,
        private readonly HistoricMediaGraphPersister $persister,
    ) {}

    /** @param array<string, mixed> $bundle */
    public function prepareService(array $bundle, int $serviceIndex = 0): HistoricProcessingResultImportPlan
    {
        $bundle = $this->bundles->validate($bundle);
        $service = $bundle['services'][$serviceIndex] ?? null;

        if (! is_array($service)) {
            throw new RuntimeException("Bundle service index {$serviceIndex} does not exist.");
        }

        /** @var list<array{path: string, size: int, sha256: string, kind: string, roles: list<string>}> $assetManifest */
        $assetManifest = $service['assets'];
        $this->assets->verifyStaged($assetManifest);
        $classification = $this->classify($service);
        $planHash = CanonicalJson::hash([
            'bundle_hash' => $bundle['bundle_hash'],
            'service_identity' => [$service['date'], $service['service']],
            'processing_key' => $service['media_graph']['processing_key'],
            'classification' => $classification,
            'assets' => $assetManifest,
        ]);

        return new HistoricProcessingResultImportPlan(
            classification: $classification['classification'],
            reason: $classification['reason'],
            planHash: $planHash,
            bundleHash: $bundle['bundle_hash'],
            service: $service,
            assets: $assetManifest,
            existingProcessingLogId: $classification['existing_processing_log_id'],
        );
    }

    /**
     * Persist inside the caller's transaction. The returned asset list must be
     * passed to cleanup if a later outer-transaction gate fails.
     *
     * @return array{processing_log: MediaProcessingLog, created_assets: list<string>}
     */
    public function persistPreparedService(
        HistoricProcessingResultImportPlan $plan,
        string $planHash,
    ): array {
        if (! hash_equals($plan->planHash, $planHash)) {
            throw new RuntimeException('Historic processing import plan hash does not match.');
        }

        if ($plan->classification === 'already_present' && $plan->existingProcessingLogId !== null) {
            $existing = MediaProcessingLog::query()->findOrFail($plan->existingProcessingLogId);

            return ['processing_log' => $existing, 'created_assets' => []];
        }

        if ($plan->classification !== 'create') {
            throw new RuntimeException("Historic processing import is {$plan->classification}: {$plan->reason}");
        }

        return $this->persister->persist($plan);
    }

    /**
     * Standalone rehearsal entry point. Production convergence uses
     * prepareService() and persistPreparedService() inside WP9's transaction.
     *
     * @param  array<string, mixed>  $bundle
     * @return array{processing_log: MediaProcessingLog, created_assets: list<string>}
     */
    public function importService(array $bundle, string $planHash, int $serviceIndex = 0): array
    {
        $plan = $this->prepareService($bundle, $serviceIndex);
        $createdAssets = [];

        try {
            return DB::transaction(function () use ($plan, $planHash, &$createdAssets): array {
                $result = $this->persistPreparedService($plan, $planHash);
                $createdAssets = $result['created_assets'];

                return $result;
            });
        } catch (\Throwable $exception) {
            $this->assets->cleanup($createdAssets);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $service
     * @return array{classification: 'already_present'|'create'|'blocked_difference'|'conflict', reason: string, existing_processing_log_id: int|null}
     */
    private function classify(array $service): array
    {
        $graph = $service['media_graph'];
        $run = $graph['run'];
        $processingId = $graph['processing_key'];
        $fileHash = $run['file_hash'] ?? null;
        $existing = MediaProcessingLog::query()->where('processing_id', $processingId)->first();
        $hashMatch = is_string($fileHash)
            ? MediaProcessingLog::query()->where('file_hash', $fileHash)->first()
            : null;

        if ($existing instanceof MediaProcessingLog) {
            $existingHash = data_get(
                $existing->processing_metadata?->toArray(),
                'historic_promotion.logical_hash',
            );
            $existingHash ??= $this->inventory->build($existing)['logical_hash'];

            return $existingHash === $graph['logical_hash']
                ? [
                    'classification' => 'already_present',
                    'reason' => 'The exact portable media graph is already present.',
                    'existing_processing_log_id' => $existing->id,
                ]
                : [
                    'classification' => 'blocked_difference',
                    'reason' => 'The processing identity exists with different durable content.',
                    'existing_processing_log_id' => $existing->id,
                ];
        }

        if ($hashMatch instanceof MediaProcessingLog) {
            return [
                'classification' => 'conflict',
                'reason' => 'The source media hash belongs to a different processing identity.',
                'existing_processing_log_id' => $hashMatch->id,
            ];
        }

        return [
            'classification' => 'create',
            'reason' => 'No matching production media graph exists.',
            'existing_processing_log_id' => null,
        ];
    }
}
