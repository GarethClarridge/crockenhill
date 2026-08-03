<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ChurchServiceEvidenceKind;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceAssertionNormalizerTest extends TestCase
{
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
