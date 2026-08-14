<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Enums\OosEmailContentScope;
use App\Enums\OosEmailParseDisposition;
use App\Enums\SermonService;
use App\Services\Email\OosArchiveIdentityResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OosArchiveIdentityResolverTest extends TestCase
{
    #[Test]
    public function it_binds_a_single_non_contradictory_plan_to_the_manifest_identity(): void
    {
        $resolved = (new OosArchiveIdentityResolver)->resolve(
            $this->entry(),
            $this->parseResult(service: null, date: null),
        );

        $this->assertSame(SermonService::Evening, $resolved->service);
        $this->assertSame('2026-07-12', $resolved->date);
        $this->assertSame('manifest', $resolved->importMetadata['archive_identity']['method']);
    }

    #[Test]
    public function a_correction_inherits_its_manifest_validated_predecessor_identity(): void
    {
        $resolved = (new OosArchiveIdentityResolver)->resolve(
            $this->entry(supersedesSourceKey: '<original>|evening:2026-07-12'),
            $this->parseResult(service: null, date: null),
        );

        $this->assertSame(SermonService::Evening, $resolved->service);
        $this->assertSame('2026-07-12', $resolved->date);
    }

    #[Test]
    public function it_does_not_override_an_explicitly_contradictory_identity(): void
    {
        $resolved = (new OosArchiveIdentityResolver)->resolve(
            $this->entry(),
            $this->parseResult(service: SermonService::Morning, date: '2026-07-19'),
        );

        $this->assertSame(SermonService::Morning, $resolved->service);
        $this->assertSame('2026-07-19', $resolved->date);
        $this->assertArrayNotHasKey('archive_identity', $resolved->importMetadata);
    }

    #[Test]
    public function it_binds_the_manifest_date_to_each_undated_non_contradictory_plan(): void
    {
        $result = $this->parseResult(service: SermonService::Morning, date: null);
        $second = new OosEmailServicePlan(
            service: SermonService::Evening,
            date: null,
            items: $result->items,
            confidence: 0.95,
            needsReview: true,
            shouldImport: false,
        );
        $result = new OosEmailParseResult(
            date: null,
            service: SermonService::Morning,
            items: $result->items,
            confidenceScore: 0.95,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$second, $result->servicePlans[0]],
        );

        $resolved = (new OosArchiveIdentityResolver)->resolve($this->entry(), $result);

        $this->assertSame(SermonService::Morning, $resolved->service);
        $this->assertSame('2026-07-12', $resolved->date);
        $this->assertSame('2026-07-12', $resolved->servicePlans[0]->date);
        $this->assertSame('2026-07-12', $resolved->servicePlans[1]->date);
        $this->assertSame(SermonService::Evening, $resolved->servicePlans[0]->service);
        $this->assertSame('manifest', $resolved->servicePlans[0]->sourceProvenance['archive_identity']);
    }

    #[Test]
    public function it_does_not_replace_an_explicitly_contradictory_date_in_a_multi_plan_email(): void
    {
        $result = $this->parseResult(service: SermonService::Morning, date: '2026-07-19');
        $undatedEvening = new OosEmailServicePlan(
            service: SermonService::Evening,
            date: null,
            items: $result->items,
            confidence: 0.95,
            needsReview: true,
            shouldImport: false,
        );
        $result = new OosEmailParseResult(
            date: '2026-07-19',
            service: SermonService::Morning,
            items: $result->items,
            confidenceScore: 0.95,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$result->servicePlans[0], $undatedEvening],
        );

        $resolved = (new OosArchiveIdentityResolver)->resolve($this->entry(), $result);

        $this->assertSame('2026-07-19', $resolved->servicePlans[0]->date);
        $this->assertNull($resolved->servicePlans[1]->date);
    }

    #[Test]
    public function an_extra_plan_with_unknown_completeness_remains_held_after_manifest_date_binding(): void
    {
        $curated = $this->parseResult(service: SermonService::Evening, date: null);
        $unknownMorning = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: null,
            items: $curated->items,
            confidence: 0.99,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            contentScope: OosEmailContentScope::Unknown,
        );
        $result = new OosEmailParseResult(
            date: null,
            service: SermonService::Evening,
            items: $curated->items,
            confidenceScore: 0.95,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$curated->servicePlans[0], $unknownMorning],
        );

        $resolved = (new OosArchiveIdentityResolver)->resolve($this->entry(), $result);

        $this->assertTrue($resolved->servicePlans[0]->isAutoImportable());
        $this->assertSame(OosEmailContentScope::Full, $resolved->servicePlans[0]->contentScope);
        $this->assertFalse($resolved->servicePlans[1]->isAutoImportable());
        $this->assertSame(OosEmailContentScope::Unknown, $resolved->servicePlans[1]->contentScope);
    }

    #[Test]
    public function it_applies_the_curated_partial_scope_to_the_corroborated_plan(): void
    {
        $entry = $this->entry(contentScope: 'partial');
        $resolved = (new OosArchiveIdentityResolver)->resolve(
            $entry,
            $this->parseResult(service: SermonService::Evening, date: '2026-07-12'),
        );

        $this->assertSame(OosEmailContentScope::Partial, $resolved->servicePlans[0]->contentScope);
        $this->assertSame('partial', $resolved->importMetadata['service_plans'][0]['content_scope']);
        $this->assertTrue($resolved->servicePlans[0]->isAutoImportable());
    }

    /**
     * F63's stated proof. The whole purpose of applying a curated scope is that the manifest
     * supplies the completeness the extractor could not determine, so a corroborated plan the
     * extractor left `unknown` must be re-classified against the scope curation just assigned —
     * not against the `unknown` the replacement plan is about to discard.
     */
    #[Test]
    public function a_corroborated_unknown_scope_plan_is_classified_against_the_curated_scope(): void
    {
        $curated = $this->parseResult(service: SermonService::Evening, date: '2026-07-12');
        $unknownScope = new OosEmailServicePlan(
            service: SermonService::Evening,
            date: '2026-07-12',
            items: $curated->items,
            confidence: 0.78,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            contentScope: OosEmailContentScope::Unknown,
        );
        $result = new OosEmailParseResult(
            date: '2026-07-12',
            service: SermonService::Evening,
            items: $curated->items,
            confidenceScore: 0.78,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$unknownScope],
            disposition: OosEmailParseDisposition::ReviewRequired,
            consensus: true,
        );

        $resolved = (new OosArchiveIdentityResolver)->resolve($this->entry(), $result);

        $this->assertSame(OosEmailContentScope::Full, $resolved->servicePlans[0]->contentScope);
        $this->assertTrue($resolved->servicePlans[0]->isAutoImportable());
    }

    private function entry(?string $supersedesSourceKey = null, string $contentScope = 'full'): OosArchiveEntry
    {
        return new OosArchiveEntry(
            index: 1,
            itemKey: '2026-07-12-pm',
            subject: 'Order of service',
            bodyPlain: 'Amazing Grace',
            groundTruthDate: '2026-07-12',
            contentScope: $contentScope,
            servicesPresent: ['evening'],
            itemLineCounts: [],
            curation: [
                'date_decision' => 'explicit',
                'date_decision_reason' => null,
                'parse_decision' => 'strict',
                'content_scope' => $contentScope,
                'partial_scope_reason' => $contentScope === 'partial' ? 'supporting details only' : null,
                'payload' => 'verbatim',
                'service_label' => null,
                'title_override' => null,
                'supersedes' => $supersedesSourceKey,
                'expected_item_count' => null,
                'decided_by' => null,
                'decided_at' => null,
                'decision_rule_version' => 'test',
            ],
            syntheticMessageId: '<test>',
            sourceKey: '<test>|evening:2026-07-12',
            supersedesSourceKey: $supersedesSourceKey,
            inputHash: str_repeat('a', 64),
            syntheticReceivedAt: CarbonImmutable::parse('2026-07-11 09:00:00'),
        );
    }

    private function parseResult(?SermonService $service, ?string $date): OosEmailParseResult
    {
        $items = [[
            'position' => 1,
            'type' => 'songs',
            'title' => 'Amazing Grace',
            'source_title' => 'Amazing Grace',
            'openlp_search_title' => null,
            'metadata' => null,
        ]];
        $plan = new OosEmailServicePlan(
            service: $service,
            date: $date,
            items: $items,
            confidence: 0.95,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
        );

        return new OosEmailParseResult(
            date: $date,
            service: $service,
            items: $items,
            confidenceScore: 0.95,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$plan],
            disposition: OosEmailParseDisposition::ReviewRequired,
        );
    }
}
