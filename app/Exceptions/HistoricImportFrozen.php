<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Import\HistoricImportMutationFreeze;
use DateTimeImmutable;
use RuntimeException;

/**
 * The refusal raised by {@see HistoricImportMutationFreeze}
 * when targeted data is frozen for a production historic-import window.
 *
 * This exists so the freeze reads as a deliberate refusal rather than an
 * outage. F46 requires targeted admin/data mutations to be frozen from final
 * preflight through closeout, but a bare `RuntimeException` surfaces to an
 * operator as HTTP 500 — indistinguishable from the incident the window's
 * monitoring is watching for, and with error tracking uninstalled the only
 * trace would be a rotating log.
 *
 * 503 with `Retry-After` is the honest status: the write is refused now,
 * the resource is expected back, and the operator is told when.
 */
final class HistoricImportFrozen extends RuntimeException
{
    public function __construct(
        public readonly ?string $operationId = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
    ) {
        parent::__construct($this->describe());
    }

    /**
     * Seconds until the freeze is expected to lift, or null when unknown.
     *
     * An elapsed expiry returns null rather than a negative or zero delay: the
     * approval expiring does not itself lift the freeze — removing the artifact
     * does — so promising an immediate retry would be a lie.
     */
    public function retryAfterSeconds(): ?int
    {
        if (! $this->expiresAt instanceof DateTimeImmutable) {
            return null;
        }

        $seconds = $this->expiresAt->getTimestamp() - (new DateTimeImmutable)->getTimestamp();

        return $seconds > 0 ? $seconds : null;
    }

    private function describe(): string
    {
        $message = 'Editing is paused for the historic import window';

        if (is_string($this->operationId) && $this->operationId !== '') {
            $message .= " (operation {$this->operationId})";
        }

        $message .= '.';

        if ($this->expiresAt instanceof DateTimeImmutable) {
            $message .= ' The approved window runs until '
                .$this->expiresAt->format('Y-m-d H:i T')
                .'; the freeze lifts when the approval artifact is removed at closeout.';
        } else {
            $message .= ' The freeze lifts when the approval artifact is removed at closeout.';
        }

        return $message;
    }
}
