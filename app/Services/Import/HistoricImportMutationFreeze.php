<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Exceptions\HistoricImportFrozen;
use DateTimeImmutable;
use Throwable;

final class HistoricImportMutationFreeze
{
    private ?string $authorizedOperationId = null;

    public function authorize(string $operationId): void
    {
        $this->authorizedOperationId = $operationId;
    }

    /**
     * Whether targeted data is frozen in this process.
     *
     * Deliberately keyed on the configured approval being non-blank rather than
     * on the artifact being readable: an unreadable approval is a reason to keep
     * refusing, not a reason to open the freeze. The artifact is only opened on
     * the refusal path, so an ordinary save pays nothing for this.
     */
    public function isFrozen(): bool
    {
        $approval = config('church.historic_corpus.production_import_approval');

        return is_string($approval) && trim($approval) !== '' && $this->authorizedOperationId === null;
    }

    public function assertMutationAllowed(): void
    {
        if (! $this->isFrozen()) {
            return;
        }

        [$operationId, $expiresAt] = $this->describeApproval();

        throw new HistoricImportFrozen($operationId, $expiresAt);
    }

    /**
     * Best-effort operator context for the refusal message.
     *
     * Every field is optional. A malformed or unreadable approval still refuses;
     * it just refuses without being able to say which operation holds the freeze.
     *
     * @return array{0: string|null, 1: DateTimeImmutable|null}
     */
    private function describeApproval(): array
    {
        $path = config('church.historic_corpus.production_import_approval');

        if (! is_string($path) || ! is_file(trim($path))) {
            return [null, null];
        }

        try {
            $approval = json_decode((string) file_get_contents(trim($path)), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [null, null];
        }

        if (! is_array($approval)) {
            return [null, null];
        }

        $operationId = $approval['operation_id'] ?? null;
        $operationId = is_string($operationId) && trim($operationId) !== '' ? trim($operationId) : null;
        $expiresAt = null;

        if (is_string($approval['expires_at'] ?? null)) {
            try {
                $expiresAt = new DateTimeImmutable($approval['expires_at']);
            } catch (Throwable) {
                $expiresAt = null;
            }
        }

        return [$operationId, $expiresAt];
    }
}
