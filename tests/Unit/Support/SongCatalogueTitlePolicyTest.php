<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\ServiceSectionSongMatchType;
use App\Services\ChurchService\Structure\ServiceStructureValidator;
use App\Support\SongCatalogueTitlePolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SongCatalogueTitlePolicyTest extends TestCase
{
    #[Test]
    public function a_confident_match_with_no_flags_writes_the_catalogue_title(): void
    {
        $this->assertTrue(SongCatalogueTitlePolicy::writesCatalogueTitle(0.95, []));
        $this->assertSame(
            ServiceSectionSongMatchType::Confirmed,
            SongCatalogueTitlePolicy::matchTypeFor(0.95, []),
        );
    }

    #[Test]
    public function a_match_below_the_threshold_stays_inferred(): void
    {
        $this->assertFalse(SongCatalogueTitlePolicy::writesCatalogueTitle(0.5, []));
        $this->assertSame(
            ServiceSectionSongMatchType::Inferred,
            SongCatalogueTitlePolicy::matchTypeFor(0.5, []),
        );
    }

    #[Test]
    public function the_threshold_is_inclusive(): void
    {
        $this->assertTrue(SongCatalogueTitlePolicy::writesCatalogueTitle(
            SongCatalogueTitlePolicy::writebackThreshold(),
            [],
        ));
    }

    /**
     * The defect this policy exists to prevent: a marker mismatch demotes a
     * match regardless of confidence, and the observed mismatches all score at
     * or near 1.0, so a confidence-only test promotes exactly the wrong rows.
     */
    #[Test]
    public function a_marker_mismatch_vetoes_the_catalogue_title_at_any_confidence(): void
    {
        foreach ([0.95, 0.98, 1.0] as $confidence) {
            $this->assertFalse(
                SongCatalogueTitlePolicy::writesCatalogueTitle(
                    $confidence,
                    [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
                ),
                "confidence {$confidence} must not defeat a marker mismatch",
            );

            $this->assertSame(
                ServiceSectionSongMatchType::Inferred,
                SongCatalogueTitlePolicy::matchTypeFor(
                    $confidence,
                    [ServiceStructureValidator::FLAG_SONG_TITLE_MARKER_MISMATCH],
                ),
            );
        }
    }

    #[Test]
    public function unrelated_flags_do_not_veto_the_catalogue_title(): void
    {
        $this->assertTrue(SongCatalogueTitlePolicy::writesCatalogueTitle(
            1.0,
            [ServiceStructureValidator::FLAG_LOW_CONFIDENCE],
        ));
    }

    #[Test]
    public function a_missing_confidence_is_never_confirmed(): void
    {
        $this->assertFalse(SongCatalogueTitlePolicy::writesCatalogueTitle(null, []));
        $this->assertSame(
            ServiceSectionSongMatchType::Inferred,
            SongCatalogueTitlePolicy::matchTypeFor(null, []),
        );
    }
}
