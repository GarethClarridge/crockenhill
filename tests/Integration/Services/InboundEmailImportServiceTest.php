<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Data\OosEmailParseResult;
use App\Data\OosEmailServicePlan;
use App\Data\StructureMergeResult;
use App\Enums\ChurchServiceItemSource;
use App\Enums\InboundEmailStatus;
use App\Enums\OosEmailParseDisposition;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceStructureMergeService;
use App\Services\Email\InboundEmailImportService;
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
    public function test_create_only_import_leaves_an_existing_openlp_service_untouched(): void
    {
        $existing = ChurchService::factory()->create([
            'date' => '2025-03-09',
            'service' => 'morning',
            'source' => 'openlp',
            'import_metadata' => ['original' => true],
        ]);
        $inboundEmail = InboundEmail::factory()->create(['status' => InboundEmailStatus::ArchiveEval]);
        $parseResult = new OosEmailParseResult(
            date: '2025-03-09',
            service: SermonService::Morning,
            items: [
                ['position' => 1, 'type' => 'songs', 'title' => 'Replacement', 'source_title' => null, 'openlp_search_title' => null, 'metadata' => null],
            ],
            confidenceScore: 0.99,
            needsReview: false,
            shouldImport: true,
            importMetadata: ['confidence_score' => 0.99],
        );

        $result = $this->service->import($inboundEmail, $parseResult, createOnly: true);

        $this->assertSame($existing->id, $result->firstResolvedService()?->id);
        $this->assertSame('openlp', $existing->fresh()->source);
        $this->assertSame(['original' => true], $existing->fresh()->import_metadata->toArray());
        $this->assertDatabaseCount('church_service_items', 0);
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
