<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Data\OosSemanticFinding;
use RuntimeException;

class OosSemanticCompilationException extends RuntimeException
{
    /** @param non-empty-list<OosSemanticFinding> $findings */
    public function __construct(public readonly array $findings)
    {
        parent::__construct(implode(' ', array_map(
            static fn (OosSemanticFinding $finding): string => "[{$finding->code}] {$finding->message}",
            $findings,
        )));
    }
}
