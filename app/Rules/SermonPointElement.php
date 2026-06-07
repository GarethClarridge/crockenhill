<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validate a single element of a sermon's `points` array.
 *
 * Each element is polymorphic: it is either a flat point (a string of at most
 * 255 characters) or a nested point shaped like
 * `['point' => string, 'sub_points' => array<int, string>]`. The nested shape's
 * inner keys are validated separately by the `points.*.point` and
 * `points.*.sub_points.*` rules, which Laravel only applies when the element is
 * an array. This rule therefore guards the flat case only: it rejects
 * non-string scalars (so the dropped `string` constraint can't be laundered
 * through, e.g. a bare integer) and over-long strings, while deferring arrays to
 * the nested rules.
 */
class SermonPointElement implements ValidationRule
{
    public function __construct(private int $maxLength = 255) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || is_array($value)) {
            return;
        }

        if (! is_string($value)) {
            $fail('The :attribute field must be a string.');

            return;
        }

        if (mb_strlen($value) > $this->maxLength) {
            $fail("The :attribute field must not be greater than {$this->maxLength} characters.");
        }
    }
}
