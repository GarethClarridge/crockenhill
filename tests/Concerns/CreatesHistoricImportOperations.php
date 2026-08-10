<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Data\HistoricImportOperationIdentity;
use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportOperation;
use Illuminate\Support\Str;

trait CreatesHistoricImportOperations
{
    /** @param array<string, mixed> $attributes */
    private function createHistoricImportOperation(?string $targetFingerprint = null, array $attributes = []): HistoricImportOperation
    {
        $identity = HistoricImportOperationIdentity::fromBindings(
            batchKey: 'test-'.Str::uuid(),
            manifestHashes: ['video' => str_repeat('a', 64)],
            planHash: str_repeat('b', 64),
            targetFingerprint: $targetFingerprint ?? str_repeat('c', 64),
            runtimeFingerprint: $targetFingerprint ?? str_repeat('c', 64),
        );

        return HistoricImportOperation::query()->create([
            ...$identity->toArray(),
            'state' => HistoricImportOperationState::Planned,
            'accepted_deadline' => now()->addDay(),
            'max_cost_minor_units' => 10_000,
            ...$attributes,
        ]);
    }
}
