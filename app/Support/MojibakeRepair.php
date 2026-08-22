<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Repairs text that was written as UTF-8 and then read as Windows-1252, so a curly quote
 * arrives as "â€™" and an en dash as "â€“".
 *
 * The OoS archive corpus carries 94 of these across 11 orders of service, and they cost the
 * song matcher every title they touch — "NIP â€˜Behold the Lambâ€™" names a song the catalogue
 * holds. The repair belongs here, at the boundary where outside text arrives, rather than in
 * the matcher: a resolver taught to read mojibake would still store the broken text, and every
 * other reader of that text would still be wrong.
 *
 * The transform is applied only when it is **reversible**. Re-encoding the repaired string the
 * way the damage was done must reproduce the input exactly; anything else — a character
 * Windows-1252 cannot hold, a sequence that is not in fact double-encoded — leaves the input
 * untouched. Text that is already correct is returned unchanged, so this is safe to apply
 * repeatedly and safe to apply to text that was never damaged.
 */
final class MojibakeRepair
{
    /**
     * A Latin-1 letter followed by a character Windows-1252 maps into the 0x80–0xBF range.
     * This is what double-encoding always looks like and what correctly encoded prose never
     * does, so it keeps the conversion off text that has nothing wrong with it.
     */
    private const Signature = '/[\x{00C0}-\x{00FF}]['
        .'\x{0080}-\x{00BF}'
        .'\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}'
        .'\x{2013}\x{2014}\x{2018}-\x{201E}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}'
        .'\x{20AC}\x{2122}'
        .']/u';

    public static function repair(string $text): string
    {
        if ($text === '' || preg_match(self::Signature, $text) !== 1) {
            return $text;
        }

        $decoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        if ($decoded === '' || ! mb_check_encoding($decoded, 'UTF-8')) {
            return $text;
        }

        // The round trip is the proof. If re-damaging the candidate does not reproduce the
        // input byte for byte, the conversion lost something and the input stands.
        if (mb_convert_encoding($decoded, 'UTF-8', 'Windows-1252') !== $text) {
            return $text;
        }

        return $decoded;
    }

    public static function repairNullable(?string $text): ?string
    {
        return $text === null ? null : self::repair($text);
    }
}
