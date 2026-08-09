<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\HistoricImportDispositionEvidence;
use App\Enums\HistoricImportDisposition;
use App\Enums\HistoricImportItemExpectation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HistoricImportDispositionEvidenceTest extends TestCase
{
    #[Test]
    #[DataProvider('nonCloseoutDispositions')]
    public function unresolved_or_failed_dispositions_never_satisfy_closeout(HistoricImportDisposition $disposition): void
    {
        $evidence = new HistoricImportDispositionEvidence(
            expectation: HistoricImportItemExpectation::Process,
            disposition: $disposition,
            approvedSourceHash: null,
            observedSourceHash: null,
            outputHashes: [],
            reasonCode: 'recorded',
        );

        $this->assertFalse($evidence->satisfiesCloseout());
    }

    /** @return iterable<string, array{HistoricImportDisposition}> */
    public static function nonCloseoutDispositions(): iterable
    {
        foreach (HistoricImportDisposition::cases() as $disposition) {
            if (in_array($disposition, [
                HistoricImportDisposition::ExactComplete,
                HistoricImportDisposition::ExactAlreadyPresent,
                HistoricImportDisposition::ApprovedExcluded,
            ], true)) {
                continue;
            }

            yield $disposition->value => [$disposition];
        }
    }

    #[Test]
    public function an_exact_complete_outcome_requires_equal_source_and_durable_output_hashes(): void
    {
        $evidence = new HistoricImportDispositionEvidence(
            expectation: HistoricImportItemExpectation::Process,
            disposition: HistoricImportDisposition::ExactComplete,
            approvedSourceHash: str_repeat('a', 64),
            observedSourceHash: str_repeat('a', 64),
            outputHashes: ['sermon_audio' => str_repeat('b', 64)],
            reasonCode: null,
        );

        $this->assertTrue($evidence->satisfiesCloseout());
    }

    #[Test]
    public function a_hash_mismatch_cannot_be_recorded_as_exact_complete(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('equal approved and observed source hashes');

        new HistoricImportDispositionEvidence(
            expectation: HistoricImportItemExpectation::Process,
            disposition: HistoricImportDisposition::ExactComplete,
            approvedSourceHash: str_repeat('a', 64),
            observedSourceHash: str_repeat('b', 64),
            outputHashes: ['sermon_audio' => str_repeat('c', 64)],
            reasonCode: null,
        );
    }

    #[Test]
    public function an_approved_exclusion_requires_a_reason_and_cannot_claim_output(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('approved exclusion cannot claim output');

        new HistoricImportDispositionEvidence(
            expectation: HistoricImportItemExpectation::Exclude,
            disposition: HistoricImportDisposition::ApprovedExcluded,
            approvedSourceHash: null,
            observedSourceHash: null,
            outputHashes: ['audio' => str_repeat('a', 64)],
            reasonCode: 'duplicate_of:item-1',
        );
    }
}
