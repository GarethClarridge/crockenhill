<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceOccurrenceState;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceProjectorTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function every_machine_source_arrival_order_produces_the_same_projection(): void
    {
        $manifests = [];

        foreach ($this->sourcePermutations() as $index => $sources) {
            $service = ChurchService::factory()->create(['date' => '2026-09-0'.($index + 1)]);

            foreach ($sources as $source) {
                $this->ingest($service, $source);
            }

            $service->refresh();
            $manifests[] = CanonicalJson::encode([
                'hash' => $service->canonical_hash,
                'source_summary' => $service->source_summary,
                'summary' => $service->summary,
                'notices' => $service->notices,
                'chapter_markers' => $service->chapter_markers,
                'items' => $service->items()
                    ->orderBy('position')
                    ->get([
                        'position',
                        'canonical_identity',
                        'type',
                        'title',
                        'source_title',
                        'occurrence_state',
                    ])
                    ->toArray(),
            ]);
        }

        $this->assertCount(1, array_unique($manifests), implode("\n", array_unique($manifests)));
    }

    #[Test]
    public function it_derives_occurrence_states_and_field_authority_from_all_active_evidence(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::Livestream);
        $this->ingest($service, ChurchServiceSource::Email);
        $this->ingest($service, ChurchServiceSource::OpenLp);

        $items = $service->items()->get()->keyBy('canonical_identity');

        $this->assertSame(
            ChurchServiceOccurrenceState::PlannedAndObserved,
            $items['song:opening-song#1']->occurrence_state,
        );
        $this->assertSame('OpenLP song title', $items['song:opening-song#1']->title);
        $this->assertSame(
            ChurchServiceOccurrenceState::ObservedOnly,
            $items['custom:welcome#1']->occurrence_state,
        );
        $this->assertSame(
            ChurchServiceOccurrenceState::PlannedOnly,
            $items['bibles:john 3:16#1']->occurrence_state,
        );
        $this->assertSame('Email sermon title', $items['custom:sermon#1']->title);
        $this->assertSame('mixed', $service->fresh()->source_summary);
    }

    #[Test]
    public function an_identical_revision_is_a_complete_no_op(): void
    {
        $service = ChurchService::factory()->create();
        $action = app(IngestChurchServiceSourceRevision::class);
        $revision = $this->revision(ChurchServiceSource::Email);

        $first = $action->execute($service, $revision);
        $canonicalRevision = $service->fresh()->canonical_revision;
        $second = $action->execute($service, $revision);

        $this->assertTrue($first->wasCreated);
        $this->assertFalse($second->wasCreated);
        $this->assertSame($first->sourceRecord->id, $second->sourceRecord->id);
        $this->assertSame($canonicalRevision, $service->fresh()->canonical_revision);
    }

    #[Test]
    public function a_manual_revision_is_the_complete_projection_and_content_authority(): void
    {
        $service = ChurchService::factory()->create();
        $this->ingest($service, ChurchServiceSource::Livestream);
        $this->ingest($service, ChurchServiceSource::Email);

        $manualRevision = new ChurchServiceSourceRevision(
            source: ChurchServiceSource::Manual,
            sourceKey: 'review-1',
            inputHash: hash('sha256', 'review-1'),
            assertions: app(ChurchServiceAssertionNormalizer::class)->normalize([
                $this->item(1, 'custom', 'Reviewed sermon', 'reviewed-sermon'),
            ], ChurchServiceEvidenceKind::Manual),
            processingFingerprint: ['format' => 'test', 'version' => 1],
            serviceContent: [
                'summary' => 'Reviewed summary',
                'notices' => [['title' => 'Reviewed notice', 'details' => null]],
                'chapter_markers' => [],
            ],
        );

        app(IngestChurchServiceSourceRevision::class)->execute($service, $manualRevision);

        $service->refresh();
        $this->assertSame('manual', $service->source_summary);
        $this->assertSame('Reviewed summary', $service->summary);
        $this->assertSame(['Reviewed sermon'], $service->items()->pluck('title')->all());
        $this->assertSame(
            ChurchServiceOccurrenceState::ManuallyConfirmed,
            $service->items()->firstOrFail()->occurrence_state,
        );
    }

    private function ingest(ChurchService $service, ChurchServiceSource $source): void
    {
        app(IngestChurchServiceSourceRevision::class)->execute(
            $service,
            $this->revision($source, (string) $service->getKey()),
        );
    }

    private function revision(ChurchServiceSource $source, string $keySuffix = 'default'): ChurchServiceSourceRevision
    {
        $items = match ($source) {
            ChurchServiceSource::Email => [
                $this->item(1, 'songs', 'Email song title', 'opening-song'),
                $this->item(2, 'custom', 'Email sermon title', 'sermon'),
            ],
            ChurchServiceSource::OpenLp => [
                $this->item(1, 'songs', 'OpenLP song title', 'opening-song'),
                $this->item(2, 'bibles', 'John 3:16', 'john 3:16'),
            ],
            ChurchServiceSource::Livestream => [
                $this->item(1, 'custom', 'Welcome', 'welcome'),
                $this->item(2, 'songs', 'Observed song title', 'opening-song'),
                $this->item(3, 'custom', 'Observed sermon title', 'sermon'),
            ],
            ChurchServiceSource::Manual => [],
        };
        $evidenceKind = $source === ChurchServiceSource::Livestream
            ? ChurchServiceEvidenceKind::Observed
            : ChurchServiceEvidenceKind::Planned;
        $assertions = app(ChurchServiceAssertionNormalizer::class)->normalize($items, $evidenceKind);

        return new ChurchServiceSourceRevision(
            source: $source,
            sourceKey: "{$source->value}-{$keySuffix}",
            inputHash: CanonicalJson::hash($items),
            assertions: $assertions,
            processingFingerprint: ['format' => 'test', 'version' => 1],
            serviceContent: $source === ChurchServiceSource::Livestream ? [
                'summary' => 'Projected summary',
                'notices' => [['title' => 'Notice', 'details' => 'Details']],
                'chapter_markers' => [['title' => 'Sermon', 'start_time' => 100.0, 'end_time' => 200.0]],
            ] : null,
        );
    }

    /**
     * @return array{position: int, type: string, title: string, source_title: string, canonical_identity: string, song_canonical_key?: string}
     */
    private function item(int $position, string $type, string $title, string $identity): array
    {
        $item = [
            'position' => $position,
            'type' => $type,
            'title' => $title,
            'source_title' => $identity,
            'canonical_identity' => $identity,
        ];

        if ($type === 'songs') {
            $item['song_canonical_key'] = $identity;
        }

        return $item;
    }

    /**
     * @return list<list<ChurchServiceSource>>
     */
    private function sourcePermutations(): array
    {
        $email = ChurchServiceSource::Email;
        $openLp = ChurchServiceSource::OpenLp;
        $livestream = ChurchServiceSource::Livestream;

        return [
            [$email, $openLp, $livestream],
            [$email, $livestream, $openLp],
            [$openLp, $email, $livestream],
            [$openLp, $livestream, $email],
            [$livestream, $email, $openLp],
            [$livestream, $openLp, $email],
        ];
    }
}
