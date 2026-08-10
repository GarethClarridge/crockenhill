<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A read surface consulted an exposure decision on a model whose deciding
 * column was never selected. Failing loudly keeps the fail-closed default from
 * masquerading as a working privacy gate.
 */
class MissingExposureAttribute extends RuntimeException {}
