<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\OpenAiSchemaLimitException;

/**
 * Refuses a strict response schema that exceeds a documented OpenAI structured-output limit before
 * the request leaves the process.
 *
 * This exists because a generated schema's size is a property of its input, not of the code that
 * built it: a schema whose per-line shape is derived from the source document can pass every test
 * written against a short document and be rejected outright on a long one. That rejection arrives
 * as an HTTP 400 naming a number with no indication of which field produced it, which is a poor
 * place to learn the arithmetic — especially on a run whose whole point is to spend money
 * deliberately.
 *
 * Counted as written, with `$defs` counted once rather than once per `$ref`. That matches how the
 * limits are documented (they are stated over "a schema", and `$defs` exist to be shared), but it is
 * an inference: if the provider ever counts a fully expanded schema instead, a request can still be
 * refused remotely after passing here. It is a guard against the failure we have measured, not a
 * proof of acceptance.
 *
 * @see https://developers.openai.com/api/docs/guides/structured-outputs
 */
class OpenAiJsonSchemaLimits
{
    public const MaxEnumValues = 1000;

    public const MaxProperties = 5000;

    public const MaxStringLength = 120_000;

    /**
     * @param  array<string, mixed>  $schema
     * @return array{enum_values:int,properties:int,string_length:int}
     */
    public static function measure(array $schema): array
    {
        $counts = ['enum_values' => 0, 'properties' => 0, 'string_length' => 0];
        self::walk($schema, $counts);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $schema
     *
     * @throws OpenAiSchemaLimitException
     */
    public static function assertWithinLimits(array $schema, string $context): void
    {
        $counts = self::measure($schema);

        $breaches = array_filter([
            $counts['enum_values'] > self::MaxEnumValues
                ? "{$counts['enum_values']} enum values (limit ".self::MaxEnumValues.')'
                : null,
            $counts['properties'] > self::MaxProperties
                ? "{$counts['properties']} object properties (limit ".self::MaxProperties.')'
                : null,
            $counts['string_length'] > self::MaxStringLength
                ? "{$counts['string_length']} characters of names and enum values (limit ".self::MaxStringLength.')'
                : null,
        ]);

        if ($breaches !== []) {
            throw new OpenAiSchemaLimitException(
                "Refusing to send the {$context} request: its response schema has ".implode(', ', $breaches).'.',
            );
        }
    }

    /**
     * Walks every node once. `enum` values are counted and not descended into; property and
     * definition names are counted where they are declared; everything else is descended into
     * generically so `items`, `anyOf` and friends need no special case.
     *
     * @param  array{enum_values:int,properties:int,string_length:int}  $counts
     */
    private static function walk(mixed $node, array &$counts): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === 'enum') {
                foreach (is_array($value) ? $value : [$value] as $entry) {
                    $counts['enum_values']++;

                    if (is_string($entry)) {
                        $counts['string_length'] += strlen($entry);
                    }
                }

                continue;
            }

            if ($key === 'const') {
                $counts['string_length'] += is_string($value) ? strlen($value) : 0;

                continue;
            }

            if (is_array($value) && in_array($key, ['properties', '$defs', 'definitions'], true)) {
                foreach ($value as $name => $member) {
                    if ($key === 'properties') {
                        $counts['properties']++;
                    }

                    $counts['string_length'] += strlen((string) $name);
                    self::walk($member, $counts);
                }

                continue;
            }

            self::walk($value, $counts);
        }
    }
}
