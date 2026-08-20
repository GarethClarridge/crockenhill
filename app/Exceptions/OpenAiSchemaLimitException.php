<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A strict response schema this application generated exceeds a documented OpenAI schema limit, so
 * the request was never sent.
 */
class OpenAiSchemaLimitException extends RuntimeException {}
