<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class NotEmptyString implements ValidationRule
{
    /**
     * Always run this rule, even when the value is an empty string.
     * Equivalent to the deprecated ImplicitRule interface.
     */
    public bool $implicit = true;

    /**
     * Run the validation rule.
     *
     * Rejects empty strings and whitespace-only strings. Passes null (pair
     * with 'nullable' in the rule set to allow null values).
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value) || trim($value) === '') {
            $fail('The :attribute field must not be empty or contain only whitespace.');
        }
    }
}
