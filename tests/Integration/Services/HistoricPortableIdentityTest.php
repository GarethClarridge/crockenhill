<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Enums\ServiceSectionType;
use App\Models\ChurchService;
use App\Models\ServiceSection;
use App\Models\Song;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\HistoricMedia\HistoricProcessingResultSectionKey;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HistoricPortableIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A local `song_id` is meaningless in the database a bundle lands in, and the
     * title a source used is not stable either. An assertion resolved from a local
     * row and one carrying only the catalogue's canonical key must therefore reach
     * the same identity — that equality is what makes a bundle portable.
     */
    #[Test]
    public function it_produces_identical_song_and_section_keys_with_different_database_ids(): void
    {
        $song = Song::factory()->create(['canonical_key' => 'shared-canonical-song']);

        $localService = ChurchService::factory()->create();
        $importedService = ChurchService::factory()->create();
        $localIdentity = $this->ingestSongAssertion($localService, 'local', [
            'song_id' => $song->id,
            'title' => 'Come Thou Fount',
        ]);
        $importedIdentity = $this->ingestSongAssertion($importedService, 'imported', [
            'song_canonical_key' => 'shared-canonical-song',
            'title' => 'Come, thou fount of every blessing',
        ]);

        $sectionKey = app(HistoricProcessingResultSectionKey::class);

        $this->assertSame('song:shared-canonical-song#1', $localIdentity);
        $this->assertSame($localIdentity, $importedIdentity);
        $this->assertSame(
            $sectionKey->for('processing-1', $this->section($song->id), $localIdentity),
            $sectionKey->for('processing-1', $this->section($song->id + 500), $importedIdentity),
        );
    }

    #[Test]
    public function it_separates_different_catalogue_songs_that_share_a_title(): void
    {
        $firstSong = Song::factory()->create(['canonical_key' => 'first-canonical-song']);
        $secondSong = Song::factory()->create(['canonical_key' => 'second-canonical-song']);

        $this->assertNotSame(
            $this->ingestSongAssertion(ChurchService::factory()->create(), 'first', [
                'song_id' => $firstSong->id,
                'title' => 'Amazing Grace',
            ]),
            $this->ingestSongAssertion(ChurchService::factory()->create(), 'second', [
                'song_id' => $secondSong->id,
                'title' => 'Amazing Grace',
            ]),
        );
    }

    /** @param array<string, mixed> $songItem */
    private function ingestSongAssertion(ChurchService $service, string $keySuffix, array $songItem): string
    {
        $items = [[
            'position' => 1,
            'type' => 'songs',
            'source_title' => $songItem['title'],
            ...$songItem,
        ]];
        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Email,
            sourceKey: "message-{$keySuffix}",
            inputHash: CanonicalJson::hash($items),
            assertions: app(ChurchServiceAssertionNormalizer::class)->normalize($items, ChurchServiceEvidenceKind::Planned),
            processingFingerprint: ['format' => 'test', 'version' => 1],
        ));

        return (string) $service->fresh()->items()->sole()->canonical_identity;
    }

    private function section(int $serviceItemId): ServiceSection
    {
        return new ServiceSection([
            'church_service_item_id' => $serviceItemId,
            'section_type' => ServiceSectionType::Song,
            'section_order' => 1,
            'title' => 'Same source song title',
            'start_time' => 12.5,
            'end_time' => 180.0,
        ]);
    }
}
