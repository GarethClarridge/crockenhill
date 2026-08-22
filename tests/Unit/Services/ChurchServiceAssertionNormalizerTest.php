<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ChurchServiceEvidenceKind;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceAssertionNormalizerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_keeps_an_openlp_search_key_separate_from_the_catalogue_song_key(): void
    {
        $assertion = app(ChurchServiceAssertionNormalizer::class)->normalize([
            [
                'position' => 1,
                'type' => 'songs',
                'title' => 'Amazing Grace',
                'openlp_search_title' => 'Grace, Amazing@',
            ],
        ], ChurchServiceEvidenceKind::Planned)[0];

        self::assertNull($assertion['song_canonical_key']);
        self::assertSame('Grace, Amazing@', $assertion['metadata']['openlp_search_title']);
        self::assertSame('grace, amazing', $assertion['metadata']['openlp_search_key']);
    }

    /**
     * Song identity is resolved from these strings, so the repair has to happen before that
     * runs rather than on the way into the columns. The 2026-08-22 replay caught the wrong
     * order: four titles were stored repaired and had already failed to resolve.
     */
    #[Test]
    public function it_repairs_the_encoding_before_song_identity_is_resolved(): void
    {
        Song::factory()->create(['title' => 'Behold The Lamb', 'canonical_key' => 'behold the lamb']);

        $assertion = app(ChurchServiceAssertionNormalizer::class)->normalize([
            [
                'position' => 1,
                'type' => 'songs',
                'title' => 'Communion Hymn: NIP â€˜Behold the Lambâ€™',
            ],
        ], ChurchServiceEvidenceKind::Planned)[0];

        self::assertSame('behold the lamb', $assertion['song_canonical_key']);
    }

    /**
     * The archive parse cache is keyed on the source file's digest rather than on its body, so
     * results extracted before the ingest-side encoding repair still arrive double-encoded.
     * Repairing here means the stored title, the assertion key and the song match key all
     * describe the song the operator actually wrote down.
     */
    #[Test]
    public function it_repairs_double_encoded_titles_before_anything_derives_from_them(): void
    {
        $assertion = app(ChurchServiceAssertionNormalizer::class)->normalize([
            [
                'position' => 1,
                'type' => 'songs',
                'title' => 'NIP â€˜Behold the Lambâ€™',
                'source_title' => 'Communion hymn: NIP â€˜Behold the Lambâ€™',
            ],
        ], ChurchServiceEvidenceKind::Planned)[0];

        self::assertSame('NIP ‘Behold the Lamb’', $assertion['title']);
        self::assertSame('Communion hymn: NIP ‘Behold the Lamb’', $assertion['source_title']);
        self::assertSame('communion hymn: nip ‘behold the lamb’', $assertion['normalized_title']);
    }

    #[Test]
    public function it_retains_an_explicit_catalogue_song_key(): void
    {
        $assertion = app(ChurchServiceAssertionNormalizer::class)->normalize([
            [
                'position' => 1,
                'type' => 'songs',
                'title' => 'Amazing Grace',
                'song_canonical_key' => 'amazing-grace',
                'openlp_search_title' => 'Grace, Amazing@',
            ],
        ], ChurchServiceEvidenceKind::Planned)[0];

        self::assertSame('amazing-grace', $assertion['song_canonical_key']);
        self::assertSame('grace, amazing', $assertion['metadata']['openlp_search_key']);
    }
}
