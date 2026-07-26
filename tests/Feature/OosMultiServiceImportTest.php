<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\InboundEmail\ApproveInboundEmailImport;
use App\Data\OosEmailImportResult;
use App\Data\OosEmailItemExtractionResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Jobs\ProcessInboundOosEmail;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\Song;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceSongLinker;
use App\Services\Email\InboundEmailImportService;
use App\Services\Email\OosEmailParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\WithInboundEmailTestHelpers;

class OosMultiServiceImportTest extends TestCase
{
    use RefreshDatabase;
    use WithInboundEmailTestHelpers;

    /**
     * A morning + evening extraction result for a genuine Sunday (12 July 2026).
     */
    private function bindMultiServiceExtractor(float $eveningConfidence = 0.95): void
    {
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'sermon', 'title' => 'Morning Sermon'],
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'sermon', 'title' => 'Evening Sermon'],
            ],
            confidence: 0.95,
            services: [
                ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                    ['type' => 'welcome', 'title' => 'Welcome'],
                    ['type' => 'sermon', 'title' => 'Morning Sermon'],
                ], 'confidence' => 0.95],
                ['service' => 'evening', 'date' => '2026-07-12', 'items' => [
                    ['type' => 'welcome', 'title' => 'Welcome'],
                    ['type' => 'sermon', 'title' => 'Evening Sermon'],
                ], 'confidence' => $eveningConfidence],
            ],
        ));
    }

    /**
     * Make song linking throw so every plan's import fails — a stand-in for a transient DB/sync
     * error inside createServiceFromPlan.
     */
    private function failSongLinking(): void
    {
        $this->mock(ChurchServiceSongLinker::class, function (MockInterface $mock): void {
            $mock->shouldReceive('linkForService')->andThrow(new RuntimeException('song sync exploded'));
        });
    }

    private function multiServiceEmail(): InboundEmail
    {
        return InboundEmail::factory()->create([
            'subject' => 'Order of Service - Sunday 12 July 2026',
            'body_plain' => "Morning\nWelcome\nMorning Sermon\n\nEvening\nWelcome\nEvening Sermon",
            'status' => InboundEmailStatus::Pending->value,
            'received_at' => '2026-07-10 09:00:00',
        ]);
    }

    #[Test]
    public function the_job_imports_both_morning_and_evening_orders(): void
    {
        $this->bindMultiServiceExtractor();
        $email = $this->multiServiceEmail();

        app()->call([new ProcessInboundOosEmail($email), 'handle']);

        $services = ChurchService::query()->orderBy('service')->get();

        $this->assertCount(2, $services);
        $this->assertEqualsCanonicalizing(
            [SermonService::Evening, SermonService::Morning],
            $services->pluck('service')->all(),
        );
        // Both typed plans carry the service date returned by the one-call extractor.
        $this->assertTrue($services->every(fn (ChurchService $service): bool => $service->date->toDateString() === '2026-07-12'));

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Processed, $email->status);
        $this->assertCount(2, $email->processing_metadata['plan_outcomes']);
        $this->assertCount(2, $email->processing_metadata['imported_church_service_ids']);
    }

    #[Test]
    public function approving_imports_both_orders_and_returns_per_plan_outcomes(): void
    {
        $this->bindMultiServiceExtractor();
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $email = $this->multiServiceEmail();

        $result = app(ApproveInboundEmailImport::class)->execute($email, $admin->id);

        $this->assertInstanceOf(OosEmailImportResult::class, $result);
        $this->assertCount(2, $result->created());
        $this->assertSame(2, ChurchService::query()->count());

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Processed, $email->status);
    }

    #[Test]
    public function the_job_imports_the_confident_plan_and_holds_the_weak_one(): void
    {
        $this->bindMultiServiceExtractor(eveningConfidence: 0.20);
        $email = $this->multiServiceEmail();

        app()->call([new ProcessInboundOosEmail($email), 'handle']);

        $services = ChurchService::query()->get();
        $this->assertCount(1, $services);
        $this->assertSame(SermonService::Morning, $services->first()->service);

        $email->refresh();
        // A held plan means the email is not fully resolved, so it stays in the inbox.
        $this->assertSame(InboundEmailStatus::Pending, $email->status);

        $outcomes = collect($email->processing_metadata['plan_outcomes']);
        $this->assertSame('created', $outcomes->firstWhere('service', 'morning')['outcome']);
        $this->assertSame('held_for_review', $outcomes->firstWhere('service', 'evening')['outcome']);
    }

    #[Test]
    public function stored_parse_result_round_trips_both_service_plans(): void
    {
        $this->bindMultiServiceExtractor();
        $email = $this->multiServiceEmail();

        $parser = app(OosEmailParserService::class);
        $importService = app(InboundEmailImportService::class);

        $parseResult = $parser->parse($email);
        $this->assertCount(2, $parseResult->servicePlans);

        $importService->storeParseResult($email, $parseResult);
        $email->refresh();

        $restored = $importService->storedParseResult($email);
        $this->assertNotNull($restored);
        $this->assertFalse($restored->isLegacyFlattened);
        $this->assertCount(2, $restored->servicePlans);
        $this->assertEqualsCanonicalizing(
            [SermonService::Morning, SermonService::Evening],
            array_map(fn ($plan) => $plan->service, $restored->servicePlans),
        );
    }

    #[Test]
    public function a_legacy_flattened_parse_cannot_be_approved_without_reparsing(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

        // A parse stored before multi-service support: a flat item list, no service_plans key.
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => [
                'parsing' => [
                    'confidence_score' => 0.95,
                    'resolved_date' => '2026-07-12',
                    'resolved_service' => 'morning',
                    'items' => [
                        ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'source_title' => 'Welcome', 'openlp_search_title' => null, 'metadata' => ['email_type' => 'welcome']],
                    ],
                    'needs_review' => false,
                    'should_import' => true,
                ],
            ],
        ]);

        $result = app(ApproveInboundEmailImport::class)->execute($email, $admin->id);

        $this->assertIsString($result);
        $this->assertStringContainsString('multi-service', $result);
        $this->assertSame(0, ChurchService::query()->count());
    }

    #[Test]
    public function an_unknown_second_plan_is_held_rather_than_absorbed_into_the_morning_slot(): void
    {
        // Morning is mapped; the second plan is genuinely unclear ("unknown"). The email-level
        // regex resolves to "morning" (first mention in the body), so the old fallback would
        // have imported this second plan into the morning slot instead of holding it.
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'sermon', 'title' => 'Morning Sermon'],
                ['type' => 'sermon', 'title' => 'Unclear Sermon'],
            ],
            confidence: 0.95,
            services: [
                ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                    ['type' => 'welcome', 'title' => 'Welcome'],
                    ['type' => 'sermon', 'title' => 'Morning Sermon'],
                ], 'confidence' => 0.95],
                ['service' => 'unknown', 'date' => '2026-07-12', 'items' => [
                    ['type' => 'sermon', 'title' => 'Unclear Sermon'],
                ], 'confidence' => 0.95],
            ],
        ));
        $email = $this->multiServiceEmail();

        app()->call([new ProcessInboundOosEmail($email), 'handle']);

        // Only the morning order is imported; the unknown plan is not silently created/merged.
        $services = ChurchService::query()->get();
        $this->assertCount(1, $services);
        $this->assertSame(SermonService::Morning, $services->first()->service);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Pending, $email->status);

        $outcomes = collect($email->processing_metadata['plan_outcomes']);
        $this->assertSame('created', $outcomes->firstWhere('service', 'morning')['outcome']);

        $held = $outcomes->firstWhere('outcome', 'held_for_review');
        $this->assertNotNull($held, 'The unknown plan should be held for review');
        $this->assertNull($held['service'], 'An unknown plan must not inherit the email-level service');
    }

    #[Test]
    public function the_job_re_raises_when_a_plan_fails_to_import(): void
    {
        $this->bindMultiServiceExtractor();
        $this->failSongLinking();
        $email = $this->multiServiceEmail();

        $threw = false;
        try {
            app()->call([new ProcessInboundOosEmail($email), 'handle']);
        } catch (RuntimeException) {
            // Re-raising is the point: it hands the failure to the queue retry/failed path.
            $threw = true;
        }

        $this->assertTrue($threw, 'A failed plan must re-raise so the queue can retry or fail the job.');

        $email->refresh();
        $this->assertNotSame(InboundEmailStatus::Processed, $email->status);
        $outcomes = collect($email->processing_metadata['plan_outcomes']);
        $this->assertTrue(
            $outcomes->contains(fn (array $outcome): bool => $outcome['outcome'] === 'failed'),
            'The per-plan failure should still be recorded before re-raising.',
        );
    }

    #[Test]
    public function approving_returns_an_error_when_a_plan_fails_to_import(): void
    {
        $this->bindMultiServiceExtractor();
        $this->failSongLinking();
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $email = $this->multiServiceEmail();

        $result = app(ApproveInboundEmailImport::class)->execute($email, $admin->id);

        $this->assertIsString($result);
        $this->assertStringContainsString('failed to import', $result);

        $email->refresh();
        $this->assertNotSame(InboundEmailStatus::Processed, $email->status);
    }

    #[Test]
    public function re_parsing_from_two_plans_to_one_drops_the_stale_plan(): void
    {
        $this->bindMultiServiceExtractor();
        $email = $this->multiServiceEmail();

        $importService = app(InboundEmailImportService::class);
        $importService->storeParseResult($email, app(OosEmailParserService::class)->parse($email));
        $email->refresh();
        $this->assertCount(2, $importService->storedParseResult($email)->servicePlans);

        // The email is re-parsed and now yields only the morning order (e.g. the evening order was
        // removed, or the model no longer sees it). The stale evening plan must not survive.
        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'sermon', 'title' => 'Morning Sermon'],
            ],
            confidence: 0.95,
            services: [
                ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                    ['type' => 'welcome', 'title' => 'Welcome'],
                    ['type' => 'sermon', 'title' => 'Morning Sermon'],
                ], 'confidence' => 0.95],
            ],
        ));

        $reparsed = app(OosEmailParserService::class)->parse($email);
        $this->assertCount(1, $reparsed->servicePlans);
        $importService->storeParseResult($email, $reparsed, isReparse: true);
        $email->refresh();

        $restored = $importService->storedParseResult($email);
        $this->assertNotNull($restored);
        $this->assertCount(1, $restored->servicePlans, 'The stale evening plan must not survive a re-parse');
        $this->assertSame(SermonService::Morning, $restored->servicePlans[0]->service);
        // The flattened primary item list is replaced wholesale too — no stale evening items linger.
        $this->assertCount(2, $restored->items);
    }

    #[Test]
    public function manual_completion_resolves_only_the_edited_plan(): void
    {
        $this->bindMultiServiceExtractor();
        $admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $email = $this->multiServiceEmail();

        $parser = app(OosEmailParserService::class);
        $importService = app(InboundEmailImportService::class);
        $importService->storeParseResult($email, $parser->parse($email));
        $email->refresh();

        $morning = ChurchService::factory()->create(['date' => '2026-07-12', 'service' => 'morning']);
        $importService->markAsProcessedFromManualReview($email, $morning, $admin->id, 'morning:2026-07-12');

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Pending, $email->status, 'Evening plan still outstanding');
        $this->assertSame(['morning:2026-07-12'], $email->processing_metadata['resolved_plan_keys']);

        $evening = ChurchService::factory()->create(['date' => '2026-07-12', 'service' => 'evening']);
        $importService->markAsProcessedFromManualReview($email, $evening, $admin->id, 'evening:2026-07-12');

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Processed, $email->status);
        $this->assertEqualsCanonicalizing(
            ['morning:2026-07-12', 'evening:2026-07-12'],
            $email->processing_metadata['resolved_plan_keys'],
        );
    }

    #[Test]
    public function an_unattended_import_writes_the_titles_a_reviewer_would_have_seen(): void
    {
        $song = Song::factory()->create([
            'title' => 'Holy Spirit, living breath of God',
            'canonical_key' => 'holy spirit living breath of god',
        ]);

        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [],
            confidence: 0.95,
            services: [
                ['service' => 'morning', 'date' => '2026-07-12', 'items' => [
                    ['type' => 'notices', 'title' => 'Notices (see above)'],
                    ['type' => 'song', 'title' => 'NIP ‘Holy, Spirit, living breath of God’'],
                    ['type' => 'bible_reading', 'title' => 'Bible Reading: Joshua 5:13-6:27'],
                ], 'confidence' => 0.95],
            ],
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - Sunday 12 July 2026',
            'body_plain' => "Notices (see above)\nNIP ‘Holy, Spirit, living breath of God’\nBible Reading: Joshua 5:13-6:27",
            'status' => InboundEmailStatus::Pending->value,
            'received_at' => '2026-07-10 09:00:00',
        ]);

        app()->call([new ProcessInboundOosEmail($email), 'handle']);

        $items = ChurchService::query()->sole()->items()->orderBy('position')->get();

        // Nobody reviewed this import, so it is the only proof that an unattended run and a
        // reviewed one now agree: cleaned titles, the catalogue's name for a matched song,
        // and the passage recorded — with every raw email line still on the item.
        $this->assertSame(
            ['Notices', 'Holy Spirit, living breath of God', 'Joshua 5:13-6:27'],
            $items->pluck('title')->all(),
        );
        $this->assertSame(
            [
                'Notices (see above)',
                'NIP ‘Holy, Spirit, living breath of God’',
                'Bible Reading: Joshua 5:13-6:27',
            ],
            $items->pluck('source_title')->all(),
        );
        $this->assertSame($song->id, $items[1]->song_id);
        $this->assertSame('Joshua 5:13-6:27', $items[2]->metadata['reading_reference'] ?? null);
    }
}
