<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a row, bundle or run carries a value that only the development
 * seeders write. Real recordings must never inherit sample values, and sample
 * rows must never be exported towards production.
 */
class SeedResidueException extends RuntimeException {}
