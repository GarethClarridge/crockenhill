<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class SongTitleMatch extends Data
{
    public const TYPE_EXACT = 'exact';

    public const TYPE_PRAISE_NUMBER = 'praise_number';

    public const TYPE_STRIPPED_NUMBER = 'stripped_number';

    public const TYPE_LOOSE_TITLE = 'loose_title';

    public const TYPE_ALTERNATE_TITLE = 'alternate_title';

    public const TYPE_FIRST_LINE = 'first_line';

    /**
     * A NIP ("not in Praise!") line matched a catalogue song that carries no hymnbook
     * number, chosen ahead of a same-titled numbered song. Inferred rather than
     * deterministic, because the source line names the song only by a prefix of the
     * catalogue's title ("my hope is built" for "My hope is built on nothing less").
     */
    public const TYPE_HYMNBOOK_ABSENT = 'hymnbook_absent';

    public const TYPE_FUZZY = 'fuzzy';

    public function __construct(
        public readonly int $songId,
        public readonly string $matchType,
        public readonly float $confidence = 1.0,
    ) {}
}
