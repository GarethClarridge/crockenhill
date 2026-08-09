<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\HistoricImportDisposition;
use App\Enums\HistoricImportItemExpectation;
use RuntimeException;

final readonly class HistoricImportDispositionEvidence
{
    /**
     * @param  array<string, string>  $outputHashes
     */
    public function __construct(
        public HistoricImportItemExpectation $expectation,
        public HistoricImportDisposition $disposition,
        public ?string $approvedSourceHash,
        public ?string $observedSourceHash,
        public array $outputHashes,
        public ?string $reasonCode,
    ) {
        $this->validate();
    }

    public function satisfiesCloseout(): bool
    {
        return $this->disposition->satisfiesCloseout($this->expectation);
    }

    private function validate(): void
    {
        foreach ($this->outputHashes as $role => $hash) {
            if ($role === '' || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1) {
                throw new RuntimeException('Historic import disposition contains invalid output hash evidence.');
            }
        }

        if ($this->expectation === HistoricImportItemExpectation::Exclude) {
            if ($this->disposition !== HistoricImportDisposition::ApprovedExcluded || $this->reasonCode === null) {
                throw new RuntimeException('An excluded source requires an approved exclusion disposition and reason.');
            }

            if ($this->outputHashes !== []) {
                throw new RuntimeException('An approved exclusion cannot claim output hashes.');
            }

            return;
        }

        if (! $this->disposition->satisfiesCloseout($this->expectation)) {
            return;
        }

        if (
            $this->approvedSourceHash === null
            || $this->observedSourceHash === null
            || ! hash_equals($this->approvedSourceHash, $this->observedSourceHash)
        ) {
            throw new RuntimeException('A closeout disposition requires equal approved and observed source hashes.');
        }

        if ($this->outputHashes === []) {
            throw new RuntimeException('A closeout disposition requires exact durable output hashes.');
        }
    }
}
