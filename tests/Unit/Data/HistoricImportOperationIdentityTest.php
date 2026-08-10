<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\HistoricImportOperationIdentity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HistoricImportOperationIdentityTest extends TestCase
{
    #[Test]
    public function it_derives_one_portable_identity_from_canonically_sorted_bindings(): void
    {
        $first = HistoricImportOperationIdentity::fromBindings(
            batchKey: 'archive-2026-08',
            manifestHashes: ['video' => str_repeat('b', 64), 'email' => str_repeat('a', 64)],
            planHash: str_repeat('c', 64),
            targetFingerprint: str_repeat('d', 64),
        );
        $second = HistoricImportOperationIdentity::fromBindings(
            batchKey: 'archive-2026-08',
            manifestHashes: ['email' => str_repeat('a', 64), 'video' => str_repeat('b', 64)],
            planHash: str_repeat('c', 64),
            targetFingerprint: str_repeat('d', 64),
        );

        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame(['email', 'video'], array_keys($first->manifestHashes));
        $this->assertStringStartsWith('historic-', $first->operationId);
    }

    #[Test]
    public function it_rejects_an_operation_id_that_does_not_match_the_binding(): void
    {
        $identity = HistoricImportOperationIdentity::fromBindings(
            batchKey: 'archive-2026-08',
            manifestHashes: ['email' => str_repeat('a', 64)],
            planHash: str_repeat('c', 64),
            targetFingerprint: str_repeat('d', 64),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('operation id does not match');

        new HistoricImportOperationIdentity(
            operationId: 'historic-'.str_repeat('0', 32),
            bindingHash: $identity->bindingHash,
            batchKey: $identity->batchKey,
            manifestHashes: $identity->manifestHashes,
            planHash: $identity->planHash,
            targetFingerprint: $identity->targetFingerprint,
            runtimeFingerprint: $identity->runtimeFingerprint,
        );
    }
}
