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
    public function it_does_not_bind_identity_when_more_than_one_plan_was_extracted(): void
    {
        $result = $this->parseResult(service: null, date: null);
        $second = new OosEmailServicePlan(
            service: null,
            date: null,
            items: $result->items,
            confidence: 0.95,
            needsReview: true,
            shouldImport: false,
        );
        $result = new OosEmailParseResult(
            date: null,
            service: null,
            items: $result->items,
            confidenceScore: 0.95,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$result->servicePlans[0], $second],
        );

        $resolved = (new OosArchiveIdentityResolver)->resolve($this->entry(), $result);

        $this->assertNull($resolved->service);
        $this->assertNull($resolved->date);
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
