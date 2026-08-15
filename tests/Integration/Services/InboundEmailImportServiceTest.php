<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Data\StructureMergeResult;
use App\Enums\ChurchServiceItemSource;
use App\Enums\ChurchServiceReviewState;
use App\Enums\InboundEmailStatus;
use App\Enums\OosEmailContentScope;
use App\Enums\OosEmailParseDisposition;
use App\Enums\OosEmailPlanHoldReason;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\Sermon;
use App\Models\Song;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceStructureMergeService;
use App\Services\ChurchService\SourceAdapters\EmailSourceAdapter;
use App\Services\Email\InboundEmailImportService;
use App\Services\Import\HistoricEmailEvidenceReleaseGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InboundEmailImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private InboundEmailImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InboundEmailImportService::class);
    }

    #[Test]
    public function test_stores_and_round_trips_a_parse_result(): void
    {
        $inboundEmail = InboundEmail::factory()->create();

        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'In Christ Alone', 'source_title' => null, 'openlp_search_title' => 'in christ alone@', 'metadata' => null],
            ],
            confidenceScore: 0.92,
            needsReview: false,
            shouldImport: true,
            importMetadata: ['confidence_score' => 0.92],
        );

        $this->service->storeParseResult($inboundEmail, $parseResult);

        $inboundEmail->refresh();
        $restored = $this->service->storedParseResult($inboundEmail);

        $this->assertNotNull($restored);
        $this->assertSame('2025-03-09', $restored->date);
        $this->assertSame(SermonService::Morning, $restored->service);
        $this->assertCount(1, $restored->items);
        $this->assertSame('In Christ Alone', $restored->items[0]['title']);
        $this->assertSame(0.92, $restored->confidenceScore);
        $this->assertFalse($restored->needsReview);
        $this->assertTrue($restored->shouldImport);
    }

    /**
     * The archive's raw-extraction cache re-resolves a decoded parse on every
     * run, so any field the encoding drops changes the outcome between the run
     * that extracted and every run afterwards.
     *
     * `section_type` was the one that was missing, and
     * ChurchServiceAssertionNormalizer reads it when a parse becomes canonical
     * assertions — so a parse restored from the inbox produced items without
     * one long before the archive noticed.
     */
    #[Test]
    public function test_encoding_a_parse_result_loses_no_item_field(): void
    {
        $item = [
            'position' => 1,
            'type' => 'songs',
            'section_type' => 'song',
            'title' => 'In Christ Alone',
            'source_title' => 'In Christ Alone (v1-4)',
            'openlp_search_title' => 'in christ alone@',
            'metadata' => ['source' => 'email'],
        ];
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: [$item],
            confidenceScore: 0.92,
            needsReview: false,
            shouldImport: true,
            importMetadata: ['confidence_score' => 0.92],
            servicePlans: [new OosEmailServicePlan(
                service: SermonService::Morning,
                date: '2025-03-09',
                items: [$item],
                confidence: 0.92,
                needsReview: false,
                shouldImport: true,
                disposition: OosEmailParseDisposition::AutoImportable,
                contentScope: OosEmailContentScope::Full,
            )],
            disposition: OosEmailParseDisposition::AutoImportable,
        );

        $decoded = $this->service->decodeParseResult($this->service->encodeParseResult($parseResult));

        $this->assertNotNull($decoded);
        $this->assertSame($item, $decoded->items[0]);
        $this->assertSame($item, $decoded->servicePlans[0]->items[0]);

        /** Encoding the decode again must be a fixed point, or reuse drifts. */
        $this->assertSame(
            $this->service->encodeParseResult($parseResult),
            $this->service->encodeParseResult($decoded),
        );
    }

    #[Test]
    public function test_stores_and_round_trips_a_service_plans_content_scope(): void
    {
        $inboundEmail = InboundEmail::factory()->create();
        $items = [
            ['position' => 1, 'type' => 'songs', 'title' => 'In Christ Alone', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
        ];
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2025-03-09',
            items: $items,
            confidence: 0.95,
            needsReview: false,
            shouldImport: true,
            contentScope: OosEmailContentScope::Partial,
        );
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: $items,
            confidenceScore: 0.95,
            needsReview: false,
            shouldImport: true,
            importMetadata: ['service_plans' => [[
                'service' => 'morning',
                'date' => '2025-03-09',
                'items' => $items,
                'confidence' => 0.95,
                'needs_review' => false,
                'should_import' => true,
                'disposition' => 'auto_importable',
                'validation_reasons' => [],
                'source_provenance' => [],
                'content_scope' => 'partial',
            ]]],
            servicePlans: [$plan],
        );

        $this->service->storeParseResult($inboundEmail, $parseResult);

        $restored = $this->service->storedParseResult($inboundEmail->fresh());

        $this->assertNotNull($restored);
        $this->assertSame(OosEmailContentScope::Partial, $restored->servicePlans[0]->contentScope);
    }

    #[Test]
    public function test_stores_the_validation_fields_from_the_parse_result_itself(): void
    {
        $inboundEmail = InboundEmail::factory()->create();

        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'In Christ Alone', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.92,
            needsReview: true,
            shouldImport: false,
            // Deliberately empty: the stored payload must come from the result's own fields
            // rather than relying on the parser having duplicated them into this bag.
            importMetadata: ['confidence_score' => 0.92],
            disposition: OosEmailParseDisposition::InvalidExtraction,
            validationReasons: ['item_line_reused'],
            consensus: true,
        );

        $this->service->storeParseResult($inboundEmail, $parseResult);

        $stored = $inboundEmail->fresh()->processing_metadata['parsing'];

        $this->assertSame('invalid_extraction', $stored['disposition']);
        $this->assertSame(['item_line_reused'], $stored['validation_reasons']);
        $this->assertTrue($stored['consensus']);

        $restored = $this->service->storedParseResult($inboundEmail->fresh());

        $this->assertNotNull($restored);
        $this->assertSame(OosEmailParseDisposition::InvalidExtraction, $restored->disposition);
        $this->assertSame(['item_line_reused'], $restored->validationReasons);
        $this->assertTrue($restored->consensus);
    }

    #[Test]
    public function test_a_stored_parse_without_a_disposition_is_never_auto_importable(): void
    {
        // Shaped like a pre-validator cache: high confidence, should_import true and
        // needs_review false, but no disposition, no validation reasons, no provenance.
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'parsing' => [
                    'resolved_date' => '2025-03-09',
                    'resolved_service' => 'morning',
                    'items' => [
                        ['position' => 1, 'type' => 'songs', 'title' => 'In Christ Alone', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                    ],
                    'confidence_score' => 0.92,
                    'needs_review' => false,
                    'should_import' => true,
                    'service_plans' => [
                        [
                            'plan_key' => 'morning:2025-03-09',
                            'service' => 'morning',
                            'date' => '2025-03-09',
                            'items' => [
                                ['position' => 1, 'type' => 'songs', 'title' => 'In Christ Alone', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                            ],
                            'confidence' => 0.92,
                            'needs_review' => false,
                            'should_import' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $restored = $this->service->storedParseResult($inboundEmail);

        $this->assertNotNull($restored);
        $this->assertSame(OosEmailParseDisposition::ReviewRequired, $restored->disposition);
        $this->assertSame(OosEmailParseDisposition::ReviewRequired, $restored->servicePlans[0]->disposition);
        $this->assertFalse($restored->servicePlans[0]->isAutoImportable());
        $this->assertTrue($restored->servicePlans[0]->isManuallyImportable());
    }

    #[Test]
    public function test_an_unattended_import_holds_a_parse_that_predates_the_validator(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'parsing' => [
                    'resolved_date' => '2025-03-09',
                    'resolved_service' => 'morning',
                    'items' => [
                        ['position' => 1, 'type' => 'songs', 'title' => 'In Christ Alone', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                    ],
                    'confidence_score' => 0.92,
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $restored = $this->service->storedParseResult($inboundEmail);
        $this->assertNotNull($restored);

        $result = $this->service->import($inboundEmail, $restored);

        $this->assertSame('held_for_review', $result->plans[0]->outcome->value);
        $this->assertDatabaseCount('church_services', 0);
    }

    #[Test]
    public function test_an_unattended_import_admits_a_corroborated_review_required_plan_as_unfinalised_evidence(): void
    {
        // REV-D2: identity is trustworthy (service + date resolved, no identity-gate hold reason)
        // even though confidence never reached the auto-import bar. The unattended pipeline now
        // imports this as source evidence rather than holding it outright.
        $inboundEmail = InboundEmail::factory()->create();
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2025-03-09',
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidence: 0.60,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            holdReasons: [OosEmailPlanHoldReason::LowConfidence],
        );
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: $plan->items,
            confidenceScore: 0.60,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$plan],
            disposition: OosEmailParseDisposition::ReviewRequired,
        );

        $result = $this->service->import($inboundEmail, $parseResult);
        $churchService = $result->firstCreatedService();

        $this->assertSame('created', $result->plans[0]->outcome->value);
        $this->assertInstanceOf(ChurchService::class, $churchService);
        $this->assertTrue((bool) $churchService->needs_review);
        $this->assertSame('review_required', $churchService->import_metadata->toArray()['plan']['disposition']);
        $this->assertSame(['low_confidence'], $churchService->import_metadata->toArray()['plan']['hold_reasons']);
        // Never touched by an automated import, so the service reads NotReviewed regardless of
        // the needs_review column above — the two are independent by design.
        $this->assertArrayNotHasKey('manual_review', $churchService->import_metadata->toArray());

        // Recorded per source key, not as one flag per service, so a service accumulating
        // evidence from several emails can still be asked whether *any* of it is unfinalised.
        $sourceKey = EmailSourceAdapter::sourceKeyFor($inboundEmail, $plan);
        $evidence = $churchService->import_metadata->toArray()['email_evidence'];

        $this->assertSame(['morning:2025-03-09'], array_column($evidence, 'plan_key'));
        $this->assertFalse($evidence[$sourceKey]['finalised']);
        $this->assertSame(['low_confidence'], $evidence[$sourceKey]['hold_reasons']);
    }

    #[Test]
    public function test_a_service_carrying_unfinalised_email_evidence_is_not_release_eligible(): void
    {
        // REV-D2 tier three: existence widens, publication does not. The evidence imported above
        // must keep its service out of a release batch until a human has reviewed it.
        $inboundEmail = InboundEmail::factory()->create();
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2025-03-09',
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidence: 0.60,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            holdReasons: [OosEmailPlanHoldReason::LowConfidence],
        );
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: $plan->items,
            confidenceScore: 0.60,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$plan],
            disposition: OosEmailParseDisposition::ReviewRequired,
        );

        $this->service->import($inboundEmail, $parseResult);

        $sermon = Sermon::factory()->create(['date' => '2025-03-09', 'service' => SermonService::Morning]);
        $gate = app(HistoricEmailEvidenceReleaseGate::class);

        $this->assertSame(['2025-03-09 morning'], $gate->ineligibleServiceLabels([$sermon->id]));

        // An admin approving the same plan finalises the evidence it covers, and the service
        // becomes releasable without anything else changing.
        $admin = User::factory()->create();
        $this->service->import($inboundEmail, $parseResult, reviewedByUserId: $admin->id);

        $this->assertSame([], $gate->ineligibleServiceLabels([$sermon->id]));
    }

    #[Test]
    public function test_an_unattended_evidence_merge_reopens_rather_than_erases_a_completed_review(): void
    {
        // Before REV-D2 only confident plans merged unattended, so this could not happen. Now a
        // low-confidence email can land on a service an operator already cleared: the review is
        // reopened with its source recorded, not silently rewound to "never reviewed".
        $reviewedAt = '2025-03-10T09:00:00+00:00';
        $reviewer = User::factory()->create();
        $existing = ChurchService::factory()->create([
            'date' => '2025-03-09',
            'service' => 'morning',
            'source' => 'email',
            'needs_review' => false,
            'review_state' => ChurchServiceReviewState::Reviewed,
            'manual_reviewed_at' => $reviewedAt,
            'manual_reviewed_by_user_id' => $reviewer->id,
            'import_metadata' => ['manual_review' => ['reviewed_at' => $reviewedAt, 'reviewed_by_user_id' => $reviewer->id]],
        ]);

        $inboundEmail = InboundEmail::factory()->create();
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2025-03-09',
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidence: 0.60,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            holdReasons: [OosEmailPlanHoldReason::LowConfidence],
        );
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: $plan->items,
            confidenceScore: 0.60,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$plan],
            disposition: OosEmailParseDisposition::ReviewRequired,
        );

        $this->service->import($inboundEmail, $parseResult);

        $fresh = $existing->fresh();
        $manualReview = $fresh->import_metadata->toArray()['manual_review'];

        $this->assertSame(ChurchServiceReviewState::Reopened, $fresh->review_state);
        $this->assertSame($reviewedAt, $manualReview['reviewed_at'], 'the human review is retained');
        $this->assertSame('email', $manualReview['reopened_by_source']);
        $this->assertNotNull($manualReview['reopened_at']);
        $this->assertTrue((bool) $fresh->needs_review);
    }

    #[Test]
    public function test_an_unattended_import_still_holds_a_content_invalid_plan(): void
    {
        // The widened evidence tier is disposition-gated: `InvalidExtraction` never becomes
        // `ReviewRequired`, so it can never satisfy isEvidenceImportable() either.
        $inboundEmail = InboundEmail::factory()->create();
        $items = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Merged title', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
        ];
        $invalidPlan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2025-03-09',
            items: $items,
            confidence: 0.95,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::InvalidExtraction,
            validationReasons: ['Item 1 merges separate source lines.'],
            holdReasons: [OosEmailPlanHoldReason::ContentInvalid],
        );
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: $items,
            confidenceScore: 0.95,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$invalidPlan],
            disposition: OosEmailParseDisposition::InvalidExtraction,
        );

        $result = $this->service->import($inboundEmail, $parseResult);

        $this->assertSame('held_for_review', $result->plans[0]->outcome->value);
        $this->assertDatabaseCount('church_services', 0);
    }

    #[Test]
    public function test_a_corroborated_review_required_plan_converges_into_an_openlp_service_without_staging(): void
    {
        // B11: cross-source agreement finalises a merge without human review. This proves the
        // widened evidence tier still reaches that convergence rather than being stranded held.
        $song = Song::factory()->create(['title' => 'In Christ Alone']);
        $existing = ChurchService::factory()->create([
            'date' => '2025-03-09',
            'service' => 'morning',
            'source' => 'openlp',
        ]);
        $existing->items()->create([
            'position' => 1,
            'type' => 'songs',
            'title' => 'In Christ Alone',
            'song_id' => $song->id,
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);
        $inboundEmail = InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending]);
        $plan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2025-03-09',
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'In Christ Alone', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidence: 0.70,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::ReviewRequired,
            holdReasons: [OosEmailPlanHoldReason::LowConfidence],
        );
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: $plan->items,
            confidenceScore: 0.70,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$plan],
            disposition: OosEmailParseDisposition::ReviewRequired,
        );

        $result = $this->service->import($inboundEmail, $parseResult);

        $this->assertSame($existing->id, $result->firstResolvedService()?->id);
        $this->assertSame([], $result->created());
        $this->assertCount(1, $result->merged());
        $this->assertSame('openlp', $existing->fresh()->source);
    }

    #[Test]
    public function test_returns_null_from_stored_parse_result_when_no_data_exists(): void
    {
        $inboundEmail = InboundEmail::factory()->create(['processing_metadata' => []]);

        $this->assertNull($this->service->storedParseResult($inboundEmail));
    }

    #[Test]
    public function test_returns_null_when_stored_data_has_no_items_key(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => ['parsing' => ['resolved_date' => '2025-03-09']],
        ]);

        $this->assertNull($this->service->storedParseResult($inboundEmail));
    }

    #[Test]
    public function test_skips_malformed_items_when_restoring(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'processing_metadata' => [
                'parsing' => [
                    'resolved_date' => '2025-03-09',
                    'resolved_service' => 'morning',
                    'items' => [
                        'not-an-array',
                        ['position' => 1, 'type' => 'songs', 'title' => 'Good Item', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                        ['missing_position' => true, 'type' => 'songs', 'title' => 'Bad Item'],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                    'confidence_score' => 0.8,
                ],
            ],
        ]);

        $restored = $this->service->storedParseResult($inboundEmail);

        $this->assertNotNull($restored);
        $this->assertCount(1, $restored->items);
        $this->assertSame('Good Item', $restored->items[0]['title']);
    }

    #[Test]
    public function test_import_creates_a_church_service(): void
    {
        $inboundEmail = InboundEmail::factory()->create();

        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.95,
            needsReview: false,
            shouldImport: true,
            importMetadata: ['confidence_score' => 0.95],
        );

        $result = $this->service->import($inboundEmail, $parseResult);
        $churchService = $result->firstCreatedService();

        $this->assertInstanceOf(ChurchService::class, $churchService);
        $this->assertDatabaseHas('church_services', [
            'date' => '2025-03-09',
            'service' => 'morning',
        ]);
        $this->assertSame('2025-03-09', (string) $churchService->date->toDateString());
        $this->assertSame(SermonService::Morning, $churchService->service);
        $this->assertCount(1, $churchService->items);
    }

    #[Test]
    public function test_import_sets_needs_review_when_parse_result_requires_it(): void
    {
        $inboundEmail = InboundEmail::factory()->create();

        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.5,
            needsReview: true,
            shouldImport: true,
            importMetadata: [],
        );

        $result = $this->service->import($inboundEmail, $parseResult);
        $churchService = $result->firstCreatedService();

        $this->assertInstanceOf(ChurchService::class, $churchService);
        $this->assertTrue((bool) $churchService->needs_review);
    }

    #[Test]
    public function test_import_clears_needs_review_when_reviewer_approves(): void
    {
        $inboundEmail = InboundEmail::factory()->create();
        $user = User::factory()->create();

        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.5,
            needsReview: true,
            shouldImport: true,
            importMetadata: [],
        );

        $result = $this->service->import($inboundEmail, $parseResult, reviewedByUserId: $user->id);
        $churchService = $result->firstCreatedService();

        $this->assertInstanceOf(ChurchService::class, $churchService);
        $this->assertFalse((bool) $churchService->needs_review);
    }

    #[Test]
    public function test_manual_approval_cannot_import_a_structurally_invalid_extraction(): void
    {
        $inboundEmail = InboundEmail::factory()->create();
        $user = User::factory()->create();
        $items = [
            ['position' => 1, 'type' => 'songs', 'title' => 'Merged title', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
        ];
        $invalidPlan = new OosEmailServicePlan(
            service: SermonService::Morning,
            date: '2025-03-09',
            items: $items,
            confidence: 0.95,
            needsReview: true,
            shouldImport: false,
            disposition: OosEmailParseDisposition::InvalidExtraction,
            validationReasons: ['Item 1 merges separate source lines.'],
        );
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: $items,
            confidenceScore: 0.95,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
            servicePlans: [$invalidPlan],
            disposition: OosEmailParseDisposition::InvalidExtraction,
        );

        $result = $this->service->import($inboundEmail, $parseResult, reviewedByUserId: $user->id);

        $this->assertSame('held_for_review', $result->plans[0]->outcome->value);
        $this->assertDatabaseCount('church_services', 0);
    }

    #[Test]
    public function test_import_updates_existing_service_rather_than_creating_a_duplicate(): void
    {
        $existing = ChurchService::factory()->create(['date' => '2025-03-09', 'service' => 'morning']);

        $inboundEmail = InboundEmail::factory()->create();

        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.9,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
        );

        $result = $this->service->import($inboundEmail, $parseResult);
        $churchService = $result->firstResolvedService();

        $this->assertInstanceOf(ChurchService::class, $churchService);
        $this->assertDatabaseCount('church_services', 1);
        $this->assertSame($existing->id, $churchService->id);
    }

    #[Test]
    public function test_import_holds_an_existing_service_for_review_when_the_merge_is_staged(): void
    {
        $existing = ChurchService::factory()->create([
            'date' => '2025-03-09',
            'service' => SermonService::Morning,
        ]);
        $inboundEmail = InboundEmail::factory()->create();
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.9,
            needsReview: false,
            shouldImport: true,
            importMetadata: [],
        );

        $this->mock(ChurchServiceStructureMergeService::class, function ($mock) use ($existing): void {
            $mock->shouldReceive('merge')
                ->once()
                ->andReturn(new StructureMergeResult(
                    churchService: $existing,
                    incomingSource: ChurchServiceItemSource::Email,
                    wasMerged: false,
                    wasStaged: true,
                    stagedConflicts: [['type' => 'type_conflict']],
                ));
        });

        $this->service = app(InboundEmailImportService::class);
        $result = $this->service->import($inboundEmail, $parseResult);

        $this->assertSame('held_for_review', $result->plans[0]->outcome->value);
        $this->assertFalse($result->isFullyResolved());
    }

    #[Test]
    public function test_import_merges_into_an_existing_openlp_service_and_keeps_its_song_links(): void
    {
        // OpenLP is the identification authority — its song links are the trustworthy part of
        // the record — while the email is the completeness authority. Merging must therefore add
        // the email's items without dropping the song identity OpenLP established.
        $song = Song::factory()->create(['title' => 'In Christ Alone']);
        $existing = ChurchService::factory()->create([
            'date' => '2025-03-09',
            'service' => 'morning',
            'source' => 'openlp',
            'import_metadata' => ['original' => true],
        ]);
        $existing->items()->create([
            'position' => 1,
            'type' => 'songs',
            'title' => 'In Christ Alone',
            'song_id' => $song->id,
            'source' => ChurchServiceItemSource::OpenLp->value,
        ]);
        $inboundEmail = InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending]);
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'In Christ Alone', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
                ['position' => 2, 'type' => 'sermon', 'title' => 'The Good Shepherd', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.99,
            needsReview: false,
            shouldImport: true,
            importMetadata: ['confidence_score' => 0.99],
        );

        $result = $this->service->import($inboundEmail, $parseResult);

        $this->assertSame($existing->id, $result->firstResolvedService()?->id);
        $this->assertSame([], $result->created());
        $this->assertCount(1, $result->merged());
        $this->assertDatabaseCount('church_services', 1);
        $this->assertSame('openlp', $existing->fresh()->source);

        // The email adds the sermon an OpenLP export structurally cannot carry, and the song
        // keeps the catalogue link it already had.
        $items = $existing->items()->orderBy('position')->get();
        $this->assertSame(['In Christ Alone', 'The Good Shepherd'], $items->pluck('title')->all());
        $this->assertSame($song->id, $items->firstOrFail()->song_id);
        $this->assertTrue($existing->fresh()->import_metadata->toArray()['original']);
        $this->assertSame(InboundEmailStatus::Processed, $inboundEmail->fresh()->status);
    }

    #[Test]
    public function test_import_throws_when_parse_result_is_missing_a_date(): void
    {
        $inboundEmail = InboundEmail::factory()->create();

        $parseResult = new OosEmailParseResult(
            date: null,
            service: SermonService::Morning,
            items: [],
            confidenceScore: 0.0,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
        );

        $this->expectException(InvalidArgumentException::class);

        $this->service->import($inboundEmail, $parseResult);
    }

    #[Test]
    public function test_import_throws_when_parse_result_is_missing_a_service(): void
    {
        $inboundEmail = InboundEmail::factory()->create();

        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: null,
            items: [],
            confidenceScore: 0.0,
            needsReview: true,
            shouldImport: false,
            importMetadata: [],
        );

        $this->expectException(InvalidArgumentException::class);

        $this->service->import($inboundEmail, $parseResult);
    }

    #[Test]
    public function test_marks_an_email_as_processed_from_manual_review(): void
    {
        $inboundEmail = InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending->value]);
        $churchService = ChurchService::factory()->create();
        $user = User::factory()->create();

        $this->service->markAsProcessedFromManualReview($inboundEmail, $churchService, $user->id);

        $inboundEmail->refresh();
        $this->assertSame(InboundEmailStatus::Processed->value, $inboundEmail->status->value);
        $this->assertSame($churchService->id, $inboundEmail->processing_metadata['imported_church_service_id']);
        $this->assertSame('manual_edit', $inboundEmail->processing_metadata['review']['mode']);
        $this->assertSame($user->id, $inboundEmail->processing_metadata['review']['approved_by_user_id']);
    }

    #[Test]
    public function test_a_manually_reviewed_import_records_which_email_it_came_from(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'message_id' => 'the-reviewed-email@crockenhill.org',
        ]);
        $churchService = ChurchService::factory()->create([
            'import_metadata' => ['manual_edit' => ['item_count' => 3]],
        ]);
        $user = User::factory()->create();

        $this->service->markAsProcessedFromManualReview($inboundEmail, $churchService, $user->id);

        $churchService->refresh();
        $this->assertSame('the-reviewed-email@crockenhill.org', $churchService->import_metadata['source_message_id']);
        $this->assertSame(3, $churchService->import_metadata['manual_edit']['item_count']);
    }
}
