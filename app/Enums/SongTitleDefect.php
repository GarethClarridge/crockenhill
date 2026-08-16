<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * A shape-level defect family an extracted song title can carry.
 *
 * Each family names one concrete, mechanically detectable signature seen in the measured corpus,
 * and each maps to exactly one {@see SongTitleHygieneVerdict} so that the census can never be read
 * as a single undifferentiated "bad title" rate. A title carries every family that fires; the
 * verdict is the highest-precedence one.
 */
enum SongTitleDefect: string
{
    use HasValues;

    /** "Song", "Hymn - Gareth to choose", "song (to follow)" — a choice not yet made. */
    case Placeholder = 'placeholder';

    /** "Sermon", "Call too worship", "NCC Q43" — a non-song item classified as a song. */
    case NotASongItem = 'not_a_song_item';

    /**
     * Cut off before the reference ends: an unclosed quote or bracket, a trailing ellipsis, or a
     * final word that cannot end a title ("Communion hymn - 429 'It is a thing most").
     */
    case Truncated = 'truncated';

    /**
     * The tail of a hard-wrapped source line, captured as an item of its own — it opens on a
     * continuation word or on closing punctuation ("words up on the screen?)", "God'").
     */
    case LineFragment = 'line_fragment';

    /** Surrounding conversation captured as the title ("Dad tells me you have the laptop..."). */
    case ProseBleed = 'prose_bleed';

    /** UTF-8 read as Latin-1 upstream, so a curly quote arrives as three Latin-1 characters. */
    case Mojibake = 'mojibake';

    /** One item naming two songs ("- 100a + 191", "Hymn 283 x 2 'All Hail the Lamb'"). */
    case MultipleSongs = 'multiple_songs';

    /** "Communion hymn -", "Final hymn:", "Carol" — the item's role, kept in front of the title. */
    case RoleLabel = 'role_label';

    /** A list bullet or quoted-reply marker the extraction kept ("- ", "* ", "> "). */
    case BulletPrefix = 'bullet_prefix';

    /** A planning duration marker ("[3m] Song: #455 Christ is risen!"). */
    case DurationPrefix = 'duration_prefix';

    /** A trailing source or author credit ("- music.ministry.org", "(EMW)", "- Stuart Townend"). */
    case AttributionSuffix = 'attribution_suffix';

    /** Markdown emphasis, a markdown link, or a bare URL ("*Hymn*: *Jesus is King* (P490)"). */
    case MarkupResidue = 'markup_residue';

    /**
     * Who owns this family's remedy.
     *
     * Precedence when several fire runs `NotATitle` > `Defective` > `Decorated`: if no title is
     * present there is nothing for a parser fix to recover, and if the text is mangled its
     * decoration is moot. The borderline case is a deferred choice that also carries damage — a
     * mis-decoded line ending "see below", pointing at a title stated elsewhere in the email. It
     * reports as `NotATitle` while keeping every flag, because routing it to extraction work would
     * be acting on a line the parser copied correctly.
     */
    public function verdict(): SongTitleHygieneVerdict
    {
        return match ($this) {
            self::Placeholder, self::NotASongItem => SongTitleHygieneVerdict::NotATitle,
            self::Truncated, self::LineFragment, self::ProseBleed,
            self::Mojibake, self::MultipleSongs => SongTitleHygieneVerdict::Defective,
            self::RoleLabel, self::BulletPrefix, self::DurationPrefix,
            self::AttributionSuffix, self::MarkupResidue => SongTitleHygieneVerdict::Decorated,
        };
    }
}
