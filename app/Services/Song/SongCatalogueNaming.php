<?php

declare(strict_types=1);

namespace App\Services\Song;

use App\Services\ChurchService\Structure\ServiceStructureValidator;

/**
 * The catalogue's answer to the two questions a caller asks about a free-text line
 * without wanting to link anything: does it name a song at all, and which song.
 *
 * It exists so {@see ServiceStructureValidator} can
 * consult the catalogue without owning it. The resolver is built at most once, on first
 * use, because it loads a lookup over the whole catalogue — a validation pass over a
 * structure with no songs must not pay for that.
 */
class SongCatalogueNaming
{
    private ?SongTitleResolver $resolver = null;

    /**
     * Build against a resolver the caller already has — the unit tests hand this a
     * {@see SongTitleResolver::fromRows()} fixture so their expectations do not move
     * with the live catalogue.
     */
    public static function using(SongTitleResolver $resolver): self
    {
        $naming = new self;
        $naming->resolver = $resolver;

        return $naming;
    }

    /**
     * Whether this text names a catalogued song at all, ambiguity included.
     */
    public function namesASong(string $title): bool
    {
        if (trim($title) === '') {
            return false;
        }

        return $this->resolver()->namesACataloguedSong($title);
    }

    /**
     * The catalogued song this text names, or null when the catalogue cannot pin it to
     * exactly one row — which includes a line that names a song it cannot separate from
     * its neighbours, so a null is never proof the text is not a song title.
     */
    public function songId(string $title): ?int
    {
        if (trim($title) === '') {
            return null;
        }

        return $this->resolver()->resolve($title)?->songId;
    }

    private function resolver(): SongTitleResolver
    {
        return $this->resolver ??= SongTitleResolver::fromDatabase();
    }
}
