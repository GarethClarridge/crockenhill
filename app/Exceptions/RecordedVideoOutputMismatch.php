<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class RecordedVideoOutputMismatch extends RuntimeException
{
    public function __construct(public string $reason)
    {
        parent::__construct($reason);
    }
}
