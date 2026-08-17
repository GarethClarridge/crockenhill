<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The extractor's response was cut off at the completion budget.
 *
 * A distinct type because this failure is a property of the *budget*, not of the model's ability
 * to read the email, and the two must not be counted together. Reasoning tokens are billed against
 * `max_completion_tokens`, so raising reasoning effort spends the same budget the visible JSON
 * needs. A budget sized for non-reasoning output therefore truncates under a reasoning effort,
 * retries up to `extraction_attempts` times, and inflates retry rate, validation failures and cost
 * at once — which reads as a defective model when it is a misconfigured ceiling.
 *
 * Any evaluation that compares reasoning efforts has to report these separately or it will
 * disqualify the stronger setting for an artefact of the harness.
 */
class OosEmailExtractionTruncatedException extends RuntimeException {}
