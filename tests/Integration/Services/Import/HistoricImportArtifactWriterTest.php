<?php

declare(strict_types=1);

namespace Tests\Integration\Services\Import;

use App\Data\HistoricImportOperationIdentity;
use App\Enums\HistoricImportArtifactKind;
use App\Enums\HistoricImportOperationState;
use App\Models\HistoricImportOperation;
use App\Services\Import\HistoricImportArtifactWriter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HistoricImportArtifactWriterTest extends TestCase
{
    use DatabaseTransactions;

    private ?HistoricImportOperation $operation = null;

    protected function tearDown(): void
    {
        if ($this->operation instanceof HistoricImportOperation) {
            $root = storage_path('app/private/historic-import/'.$this->operation->operation_id);
            $this->removeDirectory($root);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_atomically_encrypts_redacts_and_owns_a_private_artifact(): void
    {
        $operation = $this->operation();
        $artifact = app(HistoricImportArtifactWriter::class)->writeJson(
            operation: $operation,
            artifactKey: 'reports/source-closeout',
            kind: HistoricImportArtifactKind::CheckpointReport,
            relativePath: 'reports/source-closeout.json.enc',
            payload: ['status' => 'complete', 'api_key' => 'must-not-leak'],
        );
        $root = storage_path('app/private/historic-import/'.$operation->operation_id);
        $path = $root.'/reports/source-closeout.json.enc';

        $this->assertSame(0700, fileperms($root) & 0777);
        $this->assertSame(0700, fileperms($root.'/reports') & 0777);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertStringNotContainsString('must-not-leak', (string) file_get_contents($path));
        $this->assertSame(
            ['api_key' => '[REDACTED]', 'status' => 'complete'],
            json_decode(app(HistoricImportArtifactWriter::class)->read($artifact), true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertTrue($artifact->operation->is($operation));
        $this->assertTrue($artifact->encrypted);
    }

    #[Test]
    public function it_rejects_preexisting_and_tampered_artifacts(): void
    {
        $operation = $this->operation();
        $writer = app(HistoricImportArtifactWriter::class);
        $artifact = $writer->write(
            operation: $operation,
            artifactKey: 'bundle/a',
            kind: HistoricImportArtifactKind::ProcessingBundle,
            relativePath: 'bundles/a.enc',
            contents: 'approved',
        );
        $path = storage_path('app/private/historic-import/'.$operation->operation_id.'/bundles/a.enc');
        file_put_contents($path, 'tampered');

        try {
            $writer->read($artifact);
            $this->fail('Expected tampered ciphertext to fail its ownership hash.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ownership hash', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never overwrite');
        $writer->write(
            operation: $operation,
            artifactKey: 'bundle/a-second-owner',
            kind: HistoricImportArtifactKind::ProcessingBundle,
            relativePath: 'bundles/a.enc',
            contents: 'replacement',
        );
    }

    #[Test]
    public function it_rejects_a_symlinked_artifact_directory(): void
    {
        $operation = $this->operation();
        $root = storage_path('app/private/historic-import/'.$operation->operation_id);
        mkdir($root, 0700, true);
        $external = sys_get_temp_dir().'/historic-artifact-external-'.uniqid();
        mkdir($external, 0700, true);
        symlink($external, $root.'/linked');

        try {
            app(HistoricImportArtifactWriter::class)->write(
                operation: $operation,
                artifactKey: 'unsafe/link',
                kind: HistoricImportArtifactKind::Inventory,
                relativePath: 'linked/inventory.enc',
                contents: 'unsafe',
            );
            $this->fail('Expected the symlinked artifact directory to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('symlink', $exception->getMessage());
        } finally {
            rmdir($external);
        }
    }

    private function operation(): HistoricImportOperation
    {
        if ($this->operation instanceof HistoricImportOperation) {
            return $this->operation;
        }

        $identity = HistoricImportOperationIdentity::fromBindings(
            batchKey: 'artifact-writer-test',
            manifestHashes: ['video' => str_repeat('a', 64)],
            planHash: str_repeat('b', 64),
            targetFingerprint: str_repeat('c', 64),
        );

        return $this->operation = HistoricImportOperation::query()->create([
            ...$identity->toArray(),
            'state' => HistoricImportOperationState::Planned,
        ]);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isLink() || ! $item->isDir()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }

        rmdir($path);
    }
}
