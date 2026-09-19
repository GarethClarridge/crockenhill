<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Import;

use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricVideoRoundEvidenceCustody;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricVideoRoundEvidenceCustodyTest extends TestCase
{
    private string $sourceDirectory;

    private string $targetDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = (string) Str::uuid();
        $this->sourceDirectory = sys_get_temp_dir()."/video-round-source-{$suffix}";
        $this->targetDirectory = storage_path("app/private/historic-import/test-{$suffix}/closeout/video-round-evidence");
        mkdir($this->sourceDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->sourceDirectory}/*") ?: [] as $path) {
            unlink($path);
        }
        @rmdir($this->sourceDirectory);

        foreach (glob("{$this->targetDirectory}/*") ?: [] as $path) {
            unlink($path);
        }
        @rmdir($this->targetDirectory);
        @rmdir(dirname($this->targetDirectory));
        @rmdir(dirname(dirname($this->targetDirectory)));

        parent::tearDown();
    }

    #[Test]
    public function it_retains_the_exact_private_set_idempotently_and_refuses_drift(): void
    {
        $operationId = basename(dirname(dirname($this->targetDirectory)));
        $operation = new HistoricImportOperation(['operation_id' => $operationId]);
        $expectation = "{$this->sourceDirectory}/expectation.json";
        file_put_contents($expectation, 'expectation');

        foreach ($this->reportNames() as $name) {
            file_put_contents("{$this->sourceDirectory}/{$name}", $name);
        }

        $custody = app(HistoricVideoRoundEvidenceCustody::class);
        $retained = $custody->retain($operation, $this->sourceDirectory, $expectation);
        $repeated = $custody->retain($operation, $this->sourceDirectory, $expectation);

        $this->assertCount(9, $retained);
        $this->assertSame($retained, $repeated);
        $this->assertSame(0600, fileperms($retained['operation-ledger.json']) & 0777);

        file_put_contents("{$this->sourceDirectory}/operation-ledger.json", 'changed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has drifted');

        $custody->retain($operation, $this->sourceDirectory, $expectation);
    }

    /** @return list<string> */
    private function reportNames(): array
    {
        return [
            'video-status.json',
            'membership-census.json',
            'asset-audit.json',
            'scripture-settlement.json',
            'operation-ledger.json',
            'cost-duration.json',
            'manual-review-dispositions.json',
            'prior-accepted-hold-dispositions.json',
        ];
    }
}
