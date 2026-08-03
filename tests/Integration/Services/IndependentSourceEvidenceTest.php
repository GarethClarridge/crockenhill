<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Data\OosEmailParseResult;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceSource;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use App\Services\Email\InboundEmailImportService;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class IndependentSourceEvidenceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_attribute_another_sources_items_to_email_or_openlp(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-07-29',
            'service' => SermonService::Morning,
        ]);
        $this->ingestLivestreamEvidence($service);
        $this->importEmail('2026-07-29');
        $this->importOpenLp('2026-07-29');

        $assertionsBySource = $this->assertionsBySource($service);

        $this->assertSame(['Email-only sermon'], $assertionsBySource['email']);
        $this->assertSame(['OpenLP-only notice'], $assertionsBySource['openlp']);
        $this->assertSame(['Livestream-only welcome'], $assertionsBySource['livestream']);
    }

    /**
     * Source evidence must be a pure function of that source's own payload, so
     * every arrival order has to produce byte-identical assertions. The orders are
     * the interesting axis: canonical state differs wildly between them, and it is
     * exactly that state the old adapters were reading back into evidence.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function arrivalOrders(): iterable
    {
        yield 'email, openlp, livestream' => [['email', 'openlp', 'livestream']];
        yield 'email, livestream, openlp' => [['email', 'livestream', 'openlp']];
        yield 'openlp, email, livestream' => [['openlp', 'email', 'livestream']];
        yield 'openlp, livestream, email' => [['openlp', 'livestream', 'email']];
        yield 'livestream, email, openlp' => [['livestream', 'email', 'openlp']];
        yield 'livestream, openlp, email' => [['livestream', 'openlp', 'email']];
    }

    /** @param list<string> $order */
    #[Test]
    #[DataProvider('arrivalOrders')]
    public function source_assertions_are_identical_in_every_arrival_order(array $order): void
    {
        $date = '2026-07-29';
        $service = ChurchService::factory()->create([
            'date' => $date,
            'service' => SermonService::Morning,
        ]);

        foreach ($order as $source) {
            match ($source) {
                'email' => $this->importEmail($date),
                'openlp' => $this->importOpenLp($date),
                default => $this->ingestLivestreamEvidence($service),
            };
        }

        $this->assertSame([
            'email' => ['Email-only sermon'],
            'livestream' => ['Livestream-only welcome'],
            'openlp' => ['OpenLP-only notice'],
        ], $this->assertionsBySource($service)->sortKeys()->all());
    }

    /** @return Collection<string, list<string>> */
    private function assertionsBySource(ChurchService $service): Collection
    {
        return $service->fresh('sourceRecords.assertions')->sourceRecords
            ->mapWithKeys(fn ($record): array => [
                $record->source->value => $record->assertions->pluck('title')->all(),
            ]);
    }

    private function importEmail(string $date): void
    {
        app(InboundEmailImportService::class)->import(InboundEmail::factory()->create(), new OosEmailParseResult(
            date: $date,
            service: SermonService::Morning,
            items: [$this->item(1, 'Email-only sermon')],
            confidenceScore: 1.0,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
        ));
    }

    private function importOpenLp(string $date): void
    {
        app(ImportChurchServiceFromOpenLp::class)->import(OpenLpArchiveFactory::makeUpload(
            archiveName: "{$date} AM.osz",
            osjName: "{$date} AM.osj",
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::customHeader('OpenLP-only notice'),
                ),
            ]),
        ));
    }

    private function ingestLivestreamEvidence(ChurchService $service): void
    {
        $items = [$this->item(1, 'Livestream-only welcome')];

        app(IngestChurchServiceSourceRevision::class)->execute($service, new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Livestream,
            sourceKey: 'livestream-1',
            inputHash: CanonicalJson::hash($items),
            assertions: app(ChurchServiceAssertionNormalizer::class)->normalize(
                $items,
                ChurchServiceEvidenceKind::Observed,
            ),
            processingFingerprint: ['format' => 'test', 'version' => 1],
        ));
    }

    /** @return array<string, mixed> */
    private function item(int $position, string $title): array
    {
        return [
            'position' => $position,
            'type' => 'custom',
            'title' => $title,
            'source_title' => $title,
        ];
    }
}
