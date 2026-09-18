<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\SongTitleMatch;
use App\Models\ChurchServiceItem;
use App\Models\ServiceSection;
use App\Services\Song\SongTitleResolver;
use Illuminate\Console\Command;

/**
 * Give a confirmed song section the catalogue identity its order-of-service
 * item was never bound to.
 *
 * A song section takes its identity through its order-of-service item, so an
 * item holding the song's title as text but no `song_id` leaves the section
 * reading `song_match_type = confirmed` with no song behind it. Blind review
 * BC-06 found this on run 1221's §2722, whose item names "All of Us in Sin
 * Were Dying" while catalogue song 64 carries exactly that title; the census
 * behind it found 66 such sections, none of them published.
 *
 * Deliberately not `service-tracking:link-songs`. That command is the right
 * one for the catalogue as a whole, but a dry run over all 2,193 song items
 * reports 389 links updated and 3 cleared — a far wider change than binding
 * the identities a confirmed section is already asserting. This walks only
 * those items.
 *
 * Only deterministic match types are bound. A first-line, fuzzy or
 * hymbook-absent match is inferred, and the plan adjudicates those separately
 * rather than letting a run decide a song's identity by resemblance: the
 * corpus already holds sections marked `confirmed` against the wrong song.
 */
class BindConfirmedSongIdentitiesCommand extends Command
{
    protected $signature = 'service-tracking:bind-confirmed-song-identities
                            {--dry-run : Report what would be bound without writing}';

    protected $description = 'Bind catalogue songs to the order-of-service items that confirmed song sections depend on';

    /**
     * Match types a run may apply on its own: each is a deterministic reading of
     * the recorded title rather than a judgement about which song it resembles.
     */
    private const DETERMINISTIC_MATCH_TYPES = [
        SongTitleMatch::TYPE_EXACT,
        SongTitleMatch::TYPE_PRAISE_NUMBER,
        SongTitleMatch::TYPE_STRIPPED_NUMBER,
        SongTitleMatch::TYPE_LOOSE_TITLE,
        SongTitleMatch::TYPE_ALTERNATE_TITLE,
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $resolver = SongTitleResolver::fromDatabase();

        $itemIds = ServiceSection::query()
            ->where('section_type', 'song')
            ->where('song_match_type', 'confirmed')
            ->whereNotNull('church_service_item_id')
            ->distinct()
            ->pluck('church_service_item_id');

        $items = ChurchServiceItem::query()
            ->whereIn('id', $itemIds)
            ->whereNull('song_id')
            ->orderBy('id')
            ->get();

        $bound = 0;
        $inferred = [];
        $unmatched = [];

        foreach ($items as $item) {
            $title = $this->searchTitleFor($item);
            $match = $title === null ? null : $resolver->resolve($title);

            if ($match === null) {
                $unmatched[] = [$item->id, (string) $title];

                continue;
            }

            if (! in_array($match->matchType, self::DETERMINISTIC_MATCH_TYPES, true)) {
                $inferred[] = [$item->id, (string) $title, $match->songId, $match->matchType, $match->confidence];

                continue;
            }

            if (! $dryRun) {
                $item->song_id = $match->songId;
                $item->title = $resolver->catalogueTitle($match->songId) ?? $item->title;
                $item->save();
            }

            $bound++;
        }

        $this->table(['Metric', 'Value'], [
            ['Unbound items behind a confirmed section', (string) $items->count()],
            ['Bound deterministically', (string) $bound],
            ['Inferred, left for adjudication', (string) count($inferred)],
            ['No catalogue match', (string) count($unmatched)],
        ]);

        if ($inferred !== []) {
            $this->warn('Inferred matches are not bound — adjudicate these by hand:');
            $this->table(
                ['Item', 'Recorded title', 'Song', 'Match', 'Confidence'],
                array_map(
                    static fn (array $row): array => [$row[0], $row[1], $row[2], $row[3], (string) $row[4]],
                    $inferred
                ),
            );
        }

        if ($unmatched !== []) {
            $this->warn('No catalogue match — these need a catalogue decision:');
            $this->table(['Item', 'Recorded title'], $unmatched);
        }

        if ($dryRun) {
            $this->warn('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * The title to resolve against the catalogue, preferring what the source
     * actually recorded over a title a previous pass may have rewritten.
     */
    private function searchTitleFor(ChurchServiceItem $item): ?string
    {
        foreach ([$item->openlp_search_title, $item->source_title, $item->title] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
