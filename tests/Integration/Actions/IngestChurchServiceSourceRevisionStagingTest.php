<?php

declare(strict_types=1);

namespace Tests\Integration\Actions;

use App\Actions\IngestChurchServiceSourceRevision;
use App\Data\ChurchServiceSourceRevision;
use App\Enums\ChurchServiceCanonicalFinalization;
use App\Enums\ChurchServiceEvidenceKind;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceProposalStatus;
use App\Enums\ChurchServiceSource;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Services\ChurchService\ChurchServiceAssertionNormalizer;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R8 WP2/WP3: no source ingress may finish without either applying a projection or
 * staging a reviewable proposal. A revision that does neither is evidence nobody will
 * ever see.
 */
class IngestChurchServiceSourceRevisionStagingTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function legacy_items_with_no_normalized_evidence_stage_a_proposal_instead_of_vanishing(): void
    {
        $service = ChurchService::factory()->create();
        ChurchServiceItem::factory()->for($service, 'churchService')->create([
            'position' => 1,
            'type' => 'custom',
            'title' => 'Legacy hand-edited item',
            'source' => ChurchServiceItemSource::Email,
        ]);

        $this->ingest($service, ChurchServiceSource::Email, [
            $this->item(1, 'custom', 'Email item'),
        ]);

        $service->refresh();
        $proposal = $service->mergeProposals()->firstOrFail();

        $this->assertSame(ChurchServiceProposalStatus::Pending, $proposal->status);
        $this->assertTrue($service->needs_review);
        $this->assertSame('projection_requires_review', $service->review_reason);
        $this->assertContains(
            'unnormalized_legacy_items',
            array_column($proposal->conflicts, 'kind'),
        );
        $this->assertSame(
            ['Legacy hand-edited item'],
            $service->items()->pluck('title')->all(),
            'The legacy item must survive until a reviewer decides its fate.',
        );
        $this->assertNull($service->canonical_hash);
    }

    #[Test]
    public function a_projection_conflict_stages_a_proposal_rather_than_writing_canonical_items(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::Email, [
            $this->item(1, 'custom', 'Prayer'),
            $this->item(2, 'custom', 'Prayer'),
        ]);

        $this->assertCount(2, $service->fresh()->items);

        $this->ingest($service, ChurchServiceSource::OpenLp, [
            $this->item(1, 'custom', 'Prayer'),
        ]);

        $service->refresh();
        $proposal = $service->mergeProposals()->latest('id')->firstOrFail();

        $this->assertContains(
            'ambiguous_repeat_match',
            array_column($proposal->conflicts, 'kind'),
        );
        $this->assertTrue($service->needs_review);
        $this->assertNotSame([], $proposal->field_decisions);
    }

    #[Test]
    public function an_unambiguous_revision_still_applies_directly(): void
    {
        $service = ChurchService::factory()->create();

        $this->ingest($service, ChurchServiceSource::Email, [
            $this->item(1, 'custom', 'Welcome'),
            $this->item(2, 'custom', 'Sermon'),
        ]);

        $service->refresh();

        $this->assertCount(0, $service->mergeProposals);
        $this->assertNotNull($service->canonical_hash);
        $this->assertSame(['Welcome', 'Sermon'], $service->items()->orderBy('position')->pluck('title')->all());
    }

    #[Test]
    public function identical_later_machine_evidence_preserves_manual_final_authority(): void
    {
        $service = ChurchService::factory()->create();
        $items = [
            $this->item(1, 'custom', 'Welcome'),
            $this->item(2, 'custom', 'Sermon'),
        ];
        $this->ingest($service, ChurchServiceSource::Email, $items);
        $service->refresh()->forceFill([
            'reviewed_canonical_revision' => $service->canonical_revision,
            'canonical_finalization' => ChurchServiceCanonicalFinalization::Manual,
        ])->saveQuietly();
        $canonicalRevision = $service->canonical_revision;

        $this->ingest($service, ChurchServiceSource::Email, $items, str_repeat('d', 64));

        $service->refresh();
        $this->assertSame(ChurchServiceCanonicalFinalization::Manual, $service->canonical_finalization);
        $this->assertSame($canonicalRevision, $service->canonical_revision);
        $this->assertSame(['Welcome', 'Sermon'], $service->items()->orderBy('position')->pluck('title')->all());
    }

    #[Test]
    public function changed_machine_evidence_stages_review_without_erasing_manual_authority(): void
    {
        $service = ChurchService::factory()->create();
        $this->ingest($service, ChurchServiceSource::Email, [
            $this->item(1, 'custom', 'Welcome'),
            $this->item(2, 'custom', 'Sermon'),
        ]);
        $service->refresh()->forceFill([
            'reviewed_canonical_revision' => $service->canonical_revision,
            'canonical_finalization' => ChurchServiceCanonicalFinalization::Manual,
        ])->saveQuietly();

        $this->ingest($service, ChurchServiceSource::OpenLp, [
            $this->item(1, 'custom', 'Welcome'),
            $this->item(2, 'custom', 'Changed sermon'),
            $this->item(3, 'custom', 'Closing prayer'),
        ]);

        $service->refresh();
        $this->assertSame(ChurchServiceCanonicalFinalization::Manual, $service->canonical_finalization);
        $this->assertTrue($service->needs_review);
        $this->assertDatabaseHas('church_service_merge_proposals', [
            'church_service_id' => $service->id,
            'status' => ChurchServiceProposalStatus::Pending->value,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function ingest(
        ChurchService $service,
        ChurchServiceSource $source,
        array $items,
        ?string $inputHash = null,
    ): void {
        app(IngestChurchServiceSourceRevision::class)->execute(
            $service,
            new ChurchServiceSourceRevision(
                source: $source,
                sourceKey: "{$source->value}-{$service->getKey()}",
                inputHash: $inputHash ?? CanonicalJson::hash($items),
                assertions: app(ChurchServiceAssertionNormalizer::class)->normalize(
                    $items,
                    ChurchServiceEvidenceKind::Planned,
                ),
                processingFingerprint: ['format' => 'test', 'version' => 1],
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $position, string $type, string $title): array
    {
        return [
            'position' => $position,
            'type' => $type,
            'title' => $title,
            'assertion_key' => strtolower($title).'-'.$position,
        ];
    }
}
