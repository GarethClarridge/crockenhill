<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * NonRetryableTranscriptionException - Thrown when OpenAI returns a deterministic failure.
 *
 * Covers HTTP 400 (bad request / invalid format), 401 (invalid API key), and
 * 413 (payload too large). These errors will not resolve on retry, so the job
 * must fail immediately rather than burning through its remaining attempts.
 */
class NonRetryableTranscriptionException extends TranscriptionException {}
