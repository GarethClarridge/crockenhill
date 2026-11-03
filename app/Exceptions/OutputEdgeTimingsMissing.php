<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class OutputEdgeTimingsMissing extends RuntimeException
{
    /**
     * @param  array{start: float, end: float, window_rule?: string}|null  $window  The decode window that has no words, when one is known
     */
    public function __construct(string $message = '', public readonly ?array $window = null)
    {
        parent::__construct($message);
    }
}
