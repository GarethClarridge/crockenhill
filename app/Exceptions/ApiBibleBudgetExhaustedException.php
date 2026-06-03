<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown by ApiBibleClient when the daily API call budget is exhausted.
 * Callers should not retry immediately — budget resets at midnight.
 */
class ApiBibleBudgetExhaustedException extends \RuntimeException {}
