<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SongTitleDefect;
use App\Enums\SongTitleHygieneVerdict;
use Spatie\LaravelData\Data;

class SongTitleHygieneReport extends Data
{
    /**
     * @param  list<SongTitleDefect>  $defects  Every family that fired, in enum declaration order.
     * @param  string  $normalised  The title with recoverable decoration removed. Equal to
     *                              `$original` when nothing was strippable. Re-probing the resolver
     *                              with this is what turns the `Decorated` population from a
     *                              taxonomy into a sized recovery figure.
     */
    public function __construct(
        public readonly SongTitleHygieneVerdict $verdict,
        public readonly array $defects,
        public readonly string $original,
        public readonly string $normalised,
    ) {}

    public function has(SongTitleDefect $defect): bool
    {
        return in_array($defect, $this->defects, true);
    }

    /**
     * Whether normalisation changed anything, and so whether a recovery re-probe is worth running.
     *
     * Deliberately not gated on the verdict: a mis-decoded title is `Defective` because its owner
     * is the email decoder rather than the resolver, but the repair is still reversible and its
     * recovery still counts.
     */
    public function isNormalised(): bool
    {
        return $this->normalised !== $this->original;
    }

    /**
     * @return list<string>
     */
    public function defectValues(): array
    {
        return array_map(static fn (SongTitleDefect $defect): string => $defect->value, $this->defects);
    }
}
