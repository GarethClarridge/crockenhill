<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

class CanonicalJson
{
    /**
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * The human-readable form of an artifact whose hash is recorded inside it.
     *
     * This must carry every flag {@see self::encode()} uses that can change how a *value* is
     * written, and differ only in whitespace. It did not, and that silently broke the create-once
     * evidence chain: the artifact writers omitted `JSON_PRESERVE_ZERO_FRACTION`, so a float that
     * happened to land on a whole number — `1.0` from a rate over a perfect population — was
     * persisted as `1`, re-read as an `int`, and could never reproduce the hash taken over it
     * before the write. Corpus artifacts were unaffected only because they carry no computed
     * rates, which is why the defect survived so long. Encode through here rather than hand-listing
     * flags at each call site, so the two forms cannot drift apart again.
     *
     * @throws JsonException
     */
    public static function encodeReadable(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @throws JsonException
     */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::normalize(...), $value);
    }
}
