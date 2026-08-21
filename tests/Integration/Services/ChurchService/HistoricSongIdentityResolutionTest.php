<?php

declare(strict_types=1);

namespace Tests\Integration\Services\ChurchService;

use App\Enums\ChurchServiceEvidenceKind;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Planned evidence resolves song identity against the catalogue at normalisation
 * (maintainer decision 2026-08-21, §2.5), which amends invariant 4 to permit a
 * versioned catalogue dependence on `song_canonical_key` alone (§3.2).
 *
 * The cases here are the amendment's boundary, so they are deliberately literal
 * about what may and may not move under catalogue state.
 */
class HistoricSongIdentityResolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The two sides as the archive actually holds them: an Email plan carries the
     * projectionist's shorthand, OpenLP carries the archive file's own title. These
     * pairs are taken from service 297 of the catalogued rehearsal, where raw string
     * comparison reported six-of-six disagreement on an identical service.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function archiveTitlePairs(): iterable
    {
        yield 'leading label and quotes' => [
            'Come People of the Risen King',
            'NIP ‘Come People of the Risen King’',
            'Come People Of The Risen King',
        ];

        yield 'hymn number and trailing aside' => [
            'Father, Holy Spirit, Son',
            'Praise 873 ‘Father, Holy Spirit, Son’ (I like the tune to 206)',
            'Father Holy Spirit Son #837',
        ];
    }

    #[Test]
    #[DataProvider('archiveTitlePairs')]
    public function both_sources_resolve_a_decorated_title_to_the_same_catalogue_key(
        string $catalogueTitle,
        string $emailTitle,
        string $openLpTitle,
    ): void {
        $song = Song::factory()->create([
            'title' => $catalogueTitle,
            'canonical_key' => Song::canonicalizeKey($catalogueTitle),
        ]);

        $email = $this->normalizeSong($emailTitle, ChurchServiceEvidenceKind::Planned);
        $openLp = $this->normalizeSong($openLpTitle, ChurchServiceEvidenceKind::Planned);

        self::assertSame($song->canonical_key, $email['song_canonical_key']);
        self::assertSame($song->canonical_key, $openLp['song_canonical_key']);
        self::assertSame(
            $email['song_canonical_key'],
            $openLp['song_canonical_key'],
            'Independent sources must reach the same catalogue key, or corroboration compares raw text.',
        );
    }

    /**
     * The amendment narrows immutability to the resolved key. What a source said
     * must survive catalogue state untouched, or the evidence stops being evidence.
     */
    #[Test]
    public function resolution_never_rewrites_what_the_source_said(): void
    {
        Song::factory()->create([
            'title' => 'Come People of the Risen King',
            'canonical_key' => Song::canonicalizeKey('Come People of the Risen King'),
        ]);

        $decorated = 'NIP ‘Come People of the Risen King’';
        $assertion = $this->normalizeSong($decorated, ChurchServiceEvidenceKind::Planned);

        self::assertSame($decorated, $assertion['title']);
        self::assertSame($decorated, $assertion['source_title']);
        self::assertSame(mb_strtolower($decorated), $assertion['normalized_title']);
        self::assertNotNull($assertion['song_canonical_key']);
    }

    /**
     * `Praise!` is the hymnbook's own name, so it is the natural thing to type. The
     * decoration stripper handled `Praise 873` but not `Praise! 873`, which left the
     * song unlinked — and because this resolver is shared with the live item-merge
     * lane, that miss recurred on every weekly order of service carrying the form,
     * not only on the 8 historic assertions that first exposed it.
     */
    #[Test]
    public function the_hymnbooks_own_punctuation_resolves(): void
    {
        $song = Song::factory()->create([
            'title' => 'Father, Holy Spirit, Son',
            'canonical_key' => Song::canonicalizeKey('Father, Holy Spirit, Son'),
        ]);

        $assertion = $this->normalizeSong(
            'Praise! 873 ‘Father, Holy Spirit, Son’',
            ChurchServiceEvidenceKind::Planned,
        );

        self::assertSame($song->canonical_key, $assertion['song_canonical_key']);
    }

    /**
     * The forms the stripper must treat alike, so a later edit cannot fix one spelling
     * of the hymnbook's name while quietly dropping another.
     *
     * @return iterable<string, array{string}>
     */
    public static function hymnNumberForms(): iterable
    {
        yield 'bare number' => ['873 Father, Holy Spirit, Son'];
        yield 'hymnbook name' => ['Praise 873 Father, Holy Spirit, Son'];
        yield 'hymnbook name with punctuation' => ['Praise! 873 Father, Holy Spirit, Son'];
        yield 'hymnbook name with number word' => ['Praise! No. 873 Father, Holy Spirit, Son'];
    }

    #[Test]
    #[DataProvider('hymnNumberForms')]
    public function every_hymn_number_form_reaches_the_same_song(string $sourceTitle): void
    {
        $song = Song::factory()->create([
            'title' => 'Father, Holy Spirit, Son',
            'canonical_key' => Song::canonicalizeKey('Father, Holy Spirit, Son'),
        ]);

        $assertion = $this->normalizeSong($sourceTitle, ChurchServiceEvidenceKind::Planned);

        self::assertSame($song->canonical_key, $assertion['song_canonical_key']);
    }

    /**
     * Observed evidence resolves songs from lyrics and OCR, which is stronger than a
     * heard title; Manual evidence is a person's decision and is never restated by
     * machine inference (invariant 5).
     *
     * @return iterable<string, array{ChurchServiceEvidenceKind}>
     */
    public static function ineligibleEvidenceKinds(): iterable
    {
        yield 'observed' => [ChurchServiceEvidenceKind::Observed];
        yield 'manual' => [ChurchServiceEvidenceKind::Manual];
    }

    #[Test]
    #[DataProvider('ineligibleEvidenceKinds')]
    public function only_planned_evidence_resolves_against_the_catalogue(ChurchServiceEvidenceKind $kind): void
    {
        Song::factory()->create([
            'title' => 'Come People of the Risen King',
            'canonical_key' => Song::canonicalizeKey('Come People of the Risen King'),
        ]);

        $assertion = $this->normalizeSong('NIP ‘Come People of the Risen King’', $kind);

        self::assertNull($assertion['song_canonical_key']);
    }

    /**
     * The amendment requires the key to be re-derivable from the source snapshot plus
     * the catalogue, so that a catalogue change is a versioned reprojection rather
     * than an unrepeatable edit.
     */
    #[Test]
    public function the_resolved_key_is_re_derivable_from_the_same_snapshot_and_catalogue(): void
    {
        Song::factory()->create([
            'title' => 'Speak O Lord',
            'canonical_key' => Song::canonicalizeKey('Speak O Lord'),
        ]);

        $first = $this->normalizeSong('NIP ‘Speak o Lord’', ChurchServiceEvidenceKind::Planned);
        $second = $this->normalizeSong('NIP ‘Speak o Lord’', ChurchServiceEvidenceKind::Planned);

        self::assertNotNull($first['song_canonical_key']);
        self::assertSame($first['song_canonical_key'], $second['song_canonical_key']);
    }

    /** A title the catalogue does not hold stays null and falls through to the existing tiers. */
    #[Test]
    public function an_unresolved_title_stays_null_rather_than_guessing(): void
    {
        Song::factory()->create([
            'title' => 'Come People of the Risen King',
            'canonical_key' => Song::canonicalizeKey('Come People of the Risen King'),
        ]);

        $assertion = $this->normalizeSong('A Hymn The Catalogue Has Never Held', ChurchServiceEvidenceKind::Planned);

        self::assertNull($assertion['song_canonical_key']);
    }

    /** @return array<string, mixed> */
    private function normalizeSong(string $title, ChurchServiceEvidenceKind $kind): array
    {
        return app(ChurchServiceAssertionNormalizer::class)->normalize([
            [
                'position' => 1,
                'type' => 'songs',
                'title' => $title,
                'source_title' => $title,
            ],
        ], $kind)[0];
    }
}
