<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Data\OosArchiveEntry;
use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Enums\OosEmailContentScope;
use App\Enums\OosEmailParseDisposition;
use App\Enums\OosEmailPlanHoldReason;
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
    public function an_uncapped_plan_is_not_flagged_low_confidence(): void
    {
        // 0.75 is the compiled ceiling, not a middling score. Compared against the 0.90 auto-import
        // threshold this reason fired on all 710 rehearsal plans and distinguished nothing; against
        // the 0.75 review threshold an uncapped plan is correctly unflagged.
        $resolved = $this->resolveWithConfidence(0.75);

        $this->assertNotContains(OosEmailPlanHoldReason::LowConfidence, $resolved->servicePlans[0]->holdReasons);
    }

    #[Test]
    public function a_plan_capped_by_a_finding_is_flagged_low_confidence(): void
    {
        // 0.74 is what OosEmailParserService caps a plan to for a missing identity or an
        // implausible date, so it is exactly the case the reason should name.
        $resolved = $this->resolveWithConfidence(0.74);

        $this->assertContains(OosEmailPlanHoldReason::LowConfidence, $resolved->servicePlans[0]->holdReasons);
    }

    private function resolveWithConfidence(float $confidence): OosEmailParseResult
    {
        $items = $this->items();
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2026-07-12',
            items: $items,
            confidence: $confidence,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            dispositionRecorded: true,
        );

        return (new OosArchiveIdentityResolver)->resolve(
            $this->entry(service: 'morning', parseDecision: 'manifest-authoritative'),
            new OosEmailParseResult(
                date: $plan->date,
                service: $plan->service,
                items: $items,
                confidenceScore: $confidence,
                needsReview: true,
                shouldImport: false,
                importMetadata: [],
                servicePlans: [$plan],
                disposition: $plan->disposition,
            ),
        );
    }

    #[Test]
    public function manifest_authoritative_curation_selects_the_curated_service_from_a_multi_plan_parse_and_remaps_its_date(): void
    {
        $items = $this->items();
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2026-07-19',
            items: $items,
            confidence: 0.74,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            dispositionRecorded: true,
        );
        $otherPlan = new OosEmailServicePlan(
            service: SermonService::Evening,
            date: '2026-07-19',
            items: $items,
            confidence: 0.74,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            dispositionRecorded: true,
        );
        $parseResult = new OosEmailParseResult(
            date: $plan->date,
            service: $plan->service,
            items: $items,
            confidenceScore: $plan->confidence,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$plan, $otherPlan],
            disposition: $plan->disposition,
        );

        $resolved = (new OosArchiveIdentityResolver)->resolve(
            $this->entry(service: 'morning', parseDecision: 'manifest-authoritative'),
            $parseResult,
        );

        $this->assertSame(SermonService::Morning, $resolved->service);
        $this->assertSame('2026-07-12', $resolved->date);
        $this->assertSame(OosEmailParseDisposition::ReviewRequired, $resolved->disposition);
        $this->assertTrue($resolved->servicePlans[0]->isEvidenceImportable());
        $this->assertSame('manifest-authoritative', $resolved->servicePlans[0]->sourceProvenance['archive_identity']);
    }

    #[Test]
    public function manifest_authoritative_curation_does_not_choose_between_multiple_matching_plans(): void
    {
        $result = $this->parseResult(service: SermonService::Morning, date: '2026-07-19');
        $duplicate = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2026-07-26',
            items: $this->items(),
            confidence: 0.74,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            dispositionRecorded: true,
        );
        $result = new OosEmailParseResult(
            date: $result->date,
            service: $result->service,
            items: $result->items,
            confidenceScore: $result->confidenceScore,
            needsReview: $result->needsReview,
            shouldImport: $result->shouldImport,
            importMetadata: [],
            servicePlans: [$result->servicePlans[0], $duplicate],
            disposition: $result->disposition,
        );

        $resolved = (new OosArchiveIdentityResolver)->resolve(
            $this->entry(service: 'morning', parseDecision: 'manifest-authoritative'),
            $result,
        );

        $this->assertSame('2026-07-19', $resolved->date);
        $this->assertCount(2, $resolved->servicePlans);
    }

    #[Test]
    public function manifest_authoritative_curation_remaps_one_substantive_other_plan_to_the_curated_service(): void
    {
        $plan = new OosEmailServicePlan(
            service: SermonService::Other,
            date: '2026-07-19',
            items: $this->items(),
            confidence: 0.74,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::InvalidExtraction,
            dispositionRecorded: true,
            validationReasons: ['An other service requires explicit special-service evidence; ordinary notices are not a service order.'],
            contentValidationReasons: ['An other service requires explicit special-service evidence; ordinary notices are not a service order.'],
        );
        $result = new OosEmailParseResult(
            date: $plan->date,
            service: $plan->service,
            items: $plan->items,
            confidenceScore: $plan->confidence,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$plan],
            disposition: $plan->disposition,
        );

        $resolved = (new OosArchiveIdentityResolver)->resolve(
            $this->entry(service: 'morning', parseDecision: 'manifest-authoritative'),
            $result,
        );

        $this->assertSame(SermonService::Morning, $resolved->service);
        $this->assertSame('2026-07-12', $resolved->date);
        $this->assertSame(OosEmailParseDisposition::ReviewRequired, $resolved->disposition);
        $this->assertSame([], $resolved->validationReasons);
        $this->assertTrue($resolved->servicePlans[0]->isEvidenceImportable());
    }

    #[Test]
    public function a_manifest_service_assignment_reverses_only_the_recorded_early_2022_slots(): void
    {
        $items = $this->items();
        $tenThirtyPlan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2022-02-27',
            items: $items,
            confidence: 0.95,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            dispositionRecorded: true,
        );
        $twoPmPlan = new OosEmailServicePlan(
            service: SermonService::Other,
            date: '2022-02-27',
            items: $items,
            confidence: 0.95,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::InvalidExtraction,
            dispositionRecorded: true,
            validationReasons: ['An other service requires explicit special-service evidence; ordinary notices are not a service order.'],
            contentValidationReasons: ['An other service requires explicit special-service evidence; ordinary notices are not a service order.'],
        );
        $parseResult = new OosEmailParseResult(
            date: $tenThirtyPlan->date,
            service: $tenThirtyPlan->service,
            items: $items,
            confidenceScore: 0.95,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$tenThirtyPlan, $twoPmPlan],
            disposition: OosEmailParseDisposition::ReviewRequired,
            consensus: true,
        );

        $resolved = (new OosArchiveIdentityResolver)->resolve(
            $this->entry(
                service: 'morning',
                parseDecision: 'manifest-authoritative',
                additionalServices: ['evening'],
                serviceAssignments: [
                    ['source_service' => 'morning', 'resolved_service' => 'evening'],
                    ['source_service' => 'other', 'resolved_service' => 'morning'],
                ],
                date: '2022-02-27',
            ),
            $parseResult,
        );

        $this->assertSame(SermonService::Morning, $resolved->service);
        $this->assertSame(['evening', 'morning'], array_map(
            static fn (OosEmailServicePlan $plan): string => $plan->service?->value ?? 'unknown',
            $resolved->servicePlans,
        ));
        $this->assertSame([], $resolved->servicePlans[1]->validationReasons);
        $this->assertSame([
            'source_service' => 'other',
            'resolved_service' => 'morning',
        ], $resolved->servicePlans[1]->sourceProvenance['curated_service_assignment']);
    }

    #[Test]
    public function a_manifest_label_makes_a_named_other_service_evidence_importable(): void
    {
        $resolved = (new OosArchiveIdentityResolver)->resolve(
            $this->entry(service: 'other', serviceLabel: 'Good Friday'),
            $this->parseResult(service: SermonService::Other, date: '2026-07-12', confidence: 0.74),
        );

        $this->assertSame(SermonService::Other, $resolved->service);
        $this->assertNotContains(OosEmailPlanHoldReason::MissingIdentity, $resolved->servicePlans[0]->holdReasons);
        $this->assertTrue($resolved->servicePlans[0]->isEvidenceImportable());
    }

    #[Test]
    public function an_unnamed_other_service_remains_held_for_review(): void
    {
        $resolved = (new OosArchiveIdentityResolver)->resolve(
            $this->entry(service: 'other'),
            $this->parseResult(service: SermonService::Other, date: '2026-07-12'),
        );

        $this->assertContains(OosEmailPlanHoldReason::MissingIdentity, $resolved->servicePlans[0]->holdReasons);
        $this->assertFalse($resolved->servicePlans[0]->isEvidenceImportable());
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
            dispositionRecorded: true,
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
            dispositionRecorded: true,
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

    private function entry(
        ?string $supersedesSourceKey = null,
        string $contentScope = 'full',
        string $service = 'evening',
        string $parseDecision = 'strict',
        ?string $serviceLabel = null,
        array $additionalServices = [],
        array $serviceAssignments = [],
        string $date = '2026-07-12',
    ): OosArchiveEntry {
        return new OosArchiveEntry(
            index: 1,
            itemKey: '2026-07-12-pm',
            subject: 'Order of service',
            bodyPlain: 'Amazing Grace',
            groundTruthDate: $date,
            contentScope: $contentScope,
            servicesPresent: [$service, ...$additionalServices],
            itemLineCounts: [],
            curation: [
                'date_decision' => 'explicit',
                'date_decision_reason' => null,
                'parse_decision' => $parseDecision,
                'service_assignments' => $serviceAssignments,
                'content_scope' => $contentScope,
                'partial_scope_reason' => $contentScope === 'partial' ? 'supporting details only' : null,
                'payload' => 'verbatim',
                'service_label' => $serviceLabel,
                'title_override' => null,
                'supersedes' => $supersedesSourceKey,
                'expected_item_count' => null,
                'decided_by' => null,
                'decided_at' => null,
                'decision_rule_version' => 'test',
            ],
            syntheticMessageId: '<test>',
            sourceKey: "<test>|{$service}:2026-07-12",
            supersedesSourceKey: $supersedesSourceKey,
            inputHash: str_repeat('a', 64),
            syntheticReceivedAt: CarbonImmutable::parse('2026-07-11 09:00:00'),
        );
    }

    private function parseResult(?SermonService $service, ?string $date, float $confidence = 0.95): OosEmailParseResult
    {
        $items = $this->items();
        $plan = new OosEmailServicePlan(
            service: $service,
            date: $date,
            items: $items,
            confidence: $confidence,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            dispositionRecorded: true,
        );

        return new OosEmailParseResult(
            date: $date,
            service: $service,
            items: $items,
            confidenceScore: $confidence,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$plan],
            disposition: OosEmailParseDisposition::ReviewRequired,
        );
    }

    private function items(): array
    {
        return [[
            'position' => 1,
            'type' => 'songs',
            'title' => 'Amazing Grace',
            'source_title' => 'Amazing Grace',
            'openlp_search_title' => null,
            'metadata' => null,
        ]];
    }
}
