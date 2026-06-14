<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class TrimmedText implements ValidationRule
{
    /**
     * Always run this rule, even when the value is an empty string.
     * Equivalent to the deprecated ImplicitRule interface.
     */
    public bool $implicit = true;

    /**
     * Run the validation rule.
     *
     * Rejects strings with leading or trailing whitespace, or empty strings.
     * Passes null (pair with 'nullable' in the rule set to allow null values).
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value) || $value === '' || trim($value) !== $value) {
            $fail('The :attribute field must not be empty or contain leading or trailing whitespace.');
        }
    }
}
