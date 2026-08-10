<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Import\HistoricImportRuntimePreflight;
use App\Services\Import\HistoricImportTargetFingerprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrepareHistoricImportOperationCommandTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_creates_one_target_bound_operation_and_hash_chained_preparation_event(): void
    {
        $target = app(HistoricImportTargetFingerprint::class)->hash();
        $runtimeFingerprint = str_repeat('d', 64);
        $runtime = $this->createMock(HistoricImportRuntimePreflight::class);
        $runtime->method('fingerprint')->willReturn($runtimeFingerprint);
        $this->app->instance(HistoricImportRuntimePreflight::class, $runtime);
        $runtimePath = tempnam(sys_get_temp_dir(), 'historic-runtime-evidence-');
        self::assertIsString($runtimePath);
        file_put_contents($runtimePath, '{}');
        $arguments = [
            'batch' => 'approved-archive-batch',
            'plan-hash' => str_repeat('a', 64),
            '--manifest' => ['video='.str_repeat('b', 64), 'oos='.str_repeat('c', 64)],
            '--runtime-evidence' => $runtimePath,
            '--deadline' => now()->addDay()->startOfSecond()->toIso8601String(),
            '--max-cost' => '25000',
        ];

        try {
            $this->artisan('historic-import:prepare-operation', $arguments)
                ->expectsOutputToContain("Target fingerprint: {$target}")
                ->expectsOutputToContain("Runtime fingerprint: {$runtimeFingerprint}")
                ->assertSuccessful();
            $this->artisan('historic-import:prepare-operation', $arguments)->assertSuccessful();
        } finally {
            unlink($runtimePath);
        }

        $this->assertDatabaseCount('historic_import_operations', 1);
        $this->assertDatabaseHas('historic_import_operations', [
            'target_fingerprint' => $target,
            'runtime_fingerprint' => $runtimeFingerprint,
            'max_cost_minor_units' => 25_000,
        ]);
        $this->assertDatabaseCount('historic_import_journal_entries', 1);
    }
}
