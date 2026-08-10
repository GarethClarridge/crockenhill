<?php

declare(strict_types=1);

namespace App\Services\Import;

use RuntimeException;

final class HistoricImportMutationFreeze
{
    private ?string $authorizedOperationId = null;

    public function authorize(string $operationId): void
    {
        $this->authorizedOperationId = $operationId;
    }

    public function assertMutationAllowed(): void
    {
        $approval = config('church.historic_corpus.production_import_approval');

        if (! is_string($approval) || trim($approval) === '' || $this->authorizedOperationId !== null) {
            return;
        }

        throw new RuntimeException('Historic import production freeze blocks unapproved targeted data mutation.');
    }
}
