<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Contracts\ProvidesSafeMessage;
use InvalidArgumentException;

class SafeInvalidArgumentException extends InvalidArgumentException implements ProvidesSafeMessage
{
    public function getSafeMessage(): string
    {
        return $this->getMessage();
    }
}
