<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiTokenAbility;
use App\Enums\SermonService;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Jobs\ReconcileServiceSections;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\MediaProcessingLog;
use App\Models\Song;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class ChurchServiceControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function test_upload_creates_service_and_items(): void
    {
        $upload = $this->validOpenLpUpload();

        $response = $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $upload]);

        $response->assertCreated();

        $this->assertDatabaseHas('church_services', [
            'date' => '2024-11-17',
            'service' => SermonService::Morning->value,
            'source' => 'openlp',
        ]);
        $this->assertDatabaseCount('church_service_items', 2);
    }

    #[Test]
    public function test_upload_links_song_items_to_canonical_song_catalog(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'song one',
            'title' => 'Song One Canonical',
        ]);

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One', 'song one@')
                ),
            ]),
        );

        $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $upload])
            ->assertCreated()
            ->assertJsonPath('data.items.0.song_id', $song->id);

        $this->assertDatabaseHas('church_service_items', [
            'openlp_search_title' => 'song one@',
            'song_id' => $song->id,
        ]);
    }

    #[Test]
    public function test_upload_returns_church_service_resource(): void
    {
        $upload = $this->validOpenLpUpload();

        $response = $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $upload]);

        $response->assertCreated()->assertJsonStructure([
            'data' => [
                'id',
                'date',
                'service',
                'source',
                'original_filename',
                'needs_review',
                'import_metadata',
                'items' => [
                    '*' => [
                        'id',
                        'position',
                        'type',
                        'title',
                        'source_title',
                        'openlp_search_title',
                        'song_id',
                        'metadata',
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function test_re_upload_same_date_slot_updates_service(): void
    {
        $token = $this->serviceTokenFor($this->admin);

        $firstUpload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One', 'song one@')
                ),
            ]),
        );

        $this->withToken($token)
            ->postJson('/api/services/openlp', ['file' => $firstUpload])
            ->assertCreated();

        $service = ChurchService::query()->firstOrFail();
        $itemId = ChurchServiceItem::query()->firstOrFail()->id;

        $secondUpload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One Updated', 'song one@')
                ),
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::customHeader('Reading')
                ),
            ]),
        );

        $this->withToken($token)
            ->postJson('/api/services/openlp', ['file' => $secondUpload])
            ->assertCreated();

        $this->assertDatabaseCount('church_services', 1);
        $this->assertDatabaseCount('church_service_items', 2);
        $this->assertSame($service->id, ChurchService::query()->firstOrFail()->id);

        $preservedItem = ChurchServiceItem::findOrFail($itemId);
        $this->assertSame('Song One Updated', $preservedItem->title);
    }

    #[Test]
    public function test_conflicting_reupload_reopens_review_for_a_previously_reviewed_service(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'amazing grace',
            'title' => 'Amazing Grace',
        ]);

        $service = ChurchService::factory()->create([
            'date' => '2024-11-17',
            'service' => SermonService::Morning,
            'source' => 'email',
            'needs_review' => false,
            'import_metadata' => [
                'manual_review' => [
                    'reviewed_at' => now()->subDay()->toIso8601String(),
                    'reviewed_by_user_id' => $this->admin->id,
                ],
            ],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'source' => 'email',
            'title' => 'Amazing Grace (Email)',
            'source_title' => 'Amazing Grace',
            'openlp_search_title' => null,
            'song_id' => null,
        ]);

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Amazing Grace', 'amazing grace@')
                ),
            ]),
        );

        $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $upload])
            ->assertCreated();

        $service->refresh();
        $item = $service->items()->firstOrFail();

        $this->assertTrue($service->needs_review);
        $this->assertSame('Amazing Grace', $item->title);
        $this->assertSame('amazing grace@', $item->openlp_search_title);
        $this->assertSame($song->id, $item->song_id);
        $this->assertSame('openlp', $service->import_metadata['canonical_conflict_history'][0]['incoming_source'] ?? null);
        $this->assertTrue((bool) ($service->import_metadata['canonical_conflict_history'][0]['review_reopened'] ?? false));
        $this->assertSame('Service items changed after manual review.', $service->review_reason);
        $this->assertNotNull($service->import_metadata['manual_review']['reopened_at'] ?? null);
    }

    #[Test]
    public function test_clean_reimport_preserves_canonical_conflict_history_and_review_flag_until_manual_review(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2024-11-17',
            'service' => SermonService::Morning,
            'source' => 'email',
            'needs_review' => true,
            'review_reason' => 'Service items changed after manual review.',
            'import_metadata' => [
                'manual_review' => [
                    'reviewed_at' => now()->subDays(2)->toIso8601String(),
                    'reviewed_by_user_id' => $this->admin->id,
                    'reopened_at' => now()->subDay()->toIso8601String(),
                    'reopened_by_source' => 'openlp',
                ],
                'canonical_conflict' => [
                    'detected_at' => now()->subDay()->toIso8601String(),
                    'incoming_source' => 'openlp',
                    'review_reopened' => true,
                    'reviewed_previously' => true,
                    'canonical_changed' => true,
                    'changes' => [['type' => 'updated_item']],
                    'conflicts' => [],
                ],
                'canonical_conflict_history' => [[
                    'detected_at' => now()->subDay()->toIso8601String(),
                    'incoming_source' => 'openlp',
                    'review_reopened' => true,
                    'reviewed_previously' => true,
                    'canonical_changed' => true,
                    'changes' => [['type' => 'updated_item']],
                    'conflicts' => [],
                ]],
            ],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'songs',
            'source' => 'openlp',
            'title' => 'Song One',
            'source_title' => 'Song One',
            'openlp_search_title' => 'song one@',
            'metadata' => null,
        ]);

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One', 'song one@')
                ),
            ]),
        );

        $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $upload])
            ->assertCreated();

        $service->refresh();

        $this->assertTrue($service->needs_review);
        $this->assertSame('Service items changed after manual review.', $service->review_reason);
        $this->assertNotEmpty($service->import_metadata['canonical_conflict_history'] ?? []);
    }

    #[Test]
    public function test_upload_dispatches_reconciliation_for_matching_completed_processing(): void
    {
        Queue::fake();

        MediaProcessingLog::factory()->livestream()->completed()->create([
            'extracted_date' => '2024-11-17',
            'extracted_service' => SermonService::Morning->value,
        ]);

        $upload = $this->validOpenLpUpload();

        $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $upload])
            ->assertCreated();

        Queue::assertPushed(ReconcileServiceSections::class, 1);
    }

    #[Test]
    public function test_upload_emits_a_canonical_list_changed_event_after_items_are_saved(): void
    {
        Event::fake([ChurchServiceCanonicalListChanged::class]);

        $upload = $this->validOpenLpUpload();

        $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $upload])
            ->assertCreated();

        $service = ChurchService::query()->firstOrFail();

        Event::assertDispatched(
            ChurchServiceCanonicalListChanged::class,
            fn (ChurchServiceCanonicalListChanged $event): bool => $event->churchServiceId === $service->id
                && $event->source === 'openlp'
                && count($event->changes) > 0
        );
        Event::assertDispatchedTimes(ChurchServiceCanonicalListChanged::class, 1);
    }

    #[Test]
    public function test_requires_authentication(): void
    {
        $upload = $this->validOpenLpUpload();

        $this->postJson('/api/services/openlp', ['file' => $upload])
            ->assertUnauthorized();
    }

    #[Test]
    public function test_requires_admin(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $upload = $this->validOpenLpUpload();

        $this->withToken($this->serviceTokenFor($user))
            ->postJson('/api/services/openlp', ['file' => $upload])
            ->assertForbidden();
    }

    #[Test]
    public function test_rejects_non_osz_file(): void
    {
        $file = UploadedFile::fake()->create('service.txt', 1, 'text/plain');

        $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function test_show_returns_service_with_items(): void
    {
        $churchService = ChurchService::factory()->create([
            'date' => '2024-11-17',
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->count(2)->create([
            'church_service_id' => $churchService->id,
        ]);

        $this->withToken($this->serviceTokenFor($this->admin))
            ->getJson("/api/services/{$churchService->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    #[Test]
    public function test_requires_verified_email(): void
    {
        $unverifiedAdmin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $upload = $this->validOpenLpUpload();

        $this->withToken($this->serviceTokenFor($unverifiedAdmin))
            ->postJson('/api/services/openlp', ['file' => $upload])
            ->assertForbidden();
    }

    #[Test]
    public function test_pat_requires_service_upload_ability(): void
    {
        $token = $this->admin->createToken('missing-ability', ['media:process'])->plainTextToken;
        $upload = $this->validOpenLpUpload();

        $this->withToken($token)
            ->postJson('/api/services/openlp', ['file' => $upload])
            ->assertForbidden();
    }

    #[Test]
    public function test_pat_with_correct_ability_succeeds(): void
    {
        $upload = $this->validOpenLpUpload();

        $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $upload])
            ->assertCreated();
    }

    #[Test]
    public function test_session_auth_admin_succeeds(): void
    {
        Sanctum::actingAs($this->admin);

        $upload = $this->validOpenLpUpload();

        $this->postJson('/api/services/openlp', ['file' => $upload])
            ->assertCreated();
    }

    #[Test]
    public function test_rejects_oversized_file(): void
    {
        config()->set('service-tracking.upload.max_size_kb', 1);

        $file = UploadedFile::fake()->create('service.osz', 2, 'application/zip');

        $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    #[Test]
    public function test_show_requires_authentication(): void
    {
        $churchService = ChurchService::factory()->create();

        $this->getJson("/api/services/{$churchService->id}")
            ->assertUnauthorized();
    }

    #[Test]
    public function test_concurrent_duplicate_key_race_returns_existing_service(): void
    {
        // Pre-seed the service record as a concurrent request would have already inserted it.
        // The saving observer throws UniqueConstraintViolationException on the first save
        // attempt inside DB::transaction(), which rolls back the savepoint. The catch block
        // in ImportChurchServiceFromOpenLp then reloads the pre-seeded record (which survived
        // at the outer transaction level) and returns a successful response, not a 500.
        ChurchService::factory()->create([
            'date' => '2024-11-17',
            'service' => SermonService::Morning,
        ]);

        $raced = false;
        ChurchService::saving(function () use (&$raced): void {
            if (! $raced) {
                $raced = true;
                throw new UniqueConstraintViolationException('mysql', 'INSERT INTO `church_services`', [], new \PDOException('Duplicate entry'));
            }
        });

        $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $this->validOpenLpUpload()])
            ->assertCreated();

        $this->assertDatabaseCount('church_services', 1);
    }

    #[Test]
    public function test_next_returns_nearest_upcoming_service_with_ordered_items(): void
    {
        ChurchService::factory()->create([
            'date' => now()->subDays(7)->toDateString(),
            'service' => SermonService::Morning,
        ]);

        $nearest = ChurchService::factory()->create([
            'date' => now()->addDays(3)->toDateString(),
            'service' => SermonService::Morning,
        ]);

        ChurchService::factory()->create([
            'date' => now()->addDays(10)->toDateString(),
            'service' => SermonService::Morning,
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $nearest->id,
            'position' => 2,
            'title' => 'Second Item',
        ]);
        ChurchServiceItem::factory()->create([
            'church_service_id' => $nearest->id,
            'position' => 1,
            'title' => 'First Item',
        ]);

        $this->withToken($this->serviceTokenFor($this->admin))
            ->getJson('/api/services/next')
            ->assertOk()
            ->assertJsonPath('data.id', $nearest->id)
            ->assertJsonPath('data.items.0.title', 'First Item')
            ->assertJsonPath('data.items.1.title', 'Second Item');
    }

    #[Test]
    public function test_next_treats_today_as_upcoming(): void
    {
        $today = ChurchService::factory()->create([
            'date' => now()->toDateString(),
            'service' => SermonService::Morning,
        ]);

        $this->withToken($this->serviceTokenFor($this->admin))
            ->getJson('/api/services/next')
            ->assertOk()
            ->assertJsonPath('data.id', $today->id);
    }

    #[Test]
    public function test_next_prefers_morning_over_evening_on_the_same_date(): void
    {
        $date = now()->addDays(2)->toDateString();

        ChurchService::factory()->create([
            'date' => $date,
            'service' => SermonService::Evening,
        ]);

        $morning = ChurchService::factory()->create([
            'date' => $date,
            'service' => SermonService::Morning,
        ]);

        $this->withToken($this->serviceTokenFor($this->admin))
            ->getJson('/api/services/next')
            ->assertOk()
            ->assertJsonPath('data.id', $morning->id)
            ->assertJsonPath('data.service', SermonService::Morning->value);
    }

    #[Test]
    public function test_next_can_filter_by_service(): void
    {
        ChurchService::factory()->create([
            'date' => now()->addDay()->toDateString(),
            'service' => SermonService::Morning,
        ]);

        $evening = ChurchService::factory()->create([
            'date' => now()->addDays(3)->toDateString(),
            'service' => SermonService::Evening,
        ]);

        $this->withToken($this->serviceTokenFor($this->admin))
            ->getJson('/api/services/next?service=evening')
            ->assertOk()
            ->assertJsonPath('data.id', $evening->id);
    }

    #[Test]
    public function test_next_returns_404_when_no_upcoming_service_exists(): void
    {
        ChurchService::factory()->create([
            'date' => now()->subDays(7)->toDateString(),
            'service' => SermonService::Morning,
        ]);

        $this->withToken($this->serviceTokenFor($this->admin))
            ->getJson('/api/services/next')
            ->assertNotFound();
    }

    #[Test]
    public function test_next_rejects_an_invalid_service_filter(): void
    {
        ChurchService::factory()->create([
            'date' => now()->addDay()->toDateString(),
            'service' => SermonService::Morning,
        ]);

        $this->withToken($this->serviceTokenFor($this->admin))
            ->getJson('/api/services/next?service=afternoon')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service']);
    }

    #[Test]
    public function test_next_requires_authentication(): void
    {
        $this->getJson('/api/services/next')
            ->assertUnauthorized();
    }

    #[Test]
    public function test_next_requires_service_upload_ability(): void
    {
        $token = $this->admin->createToken('missing-ability', ['media:process'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/services/next')
            ->assertForbidden();
    }

    #[Test]
    public function upload_returns_404_when_service_tracking_is_disabled(): void
    {
        config(['service-tracking.enabled' => false]);

        $upload = $this->validOpenLpUpload();

        $this->withToken($this->serviceTokenFor($this->admin))
            ->postJson('/api/services/openlp', ['file' => $upload])
            ->assertNotFound();
    }

    #[Test]
    public function next_returns_404_when_service_tracking_is_disabled(): void
    {
        config(['service-tracking.enabled' => false]);

        $this->withToken($this->serviceTokenFor($this->admin))
            ->getJson('/api/services/next')
            ->assertNotFound();
    }

    #[Test]
    public function show_returns_404_when_service_tracking_is_disabled(): void
    {
        config(['service-tracking.enabled' => false]);

        $churchService = ChurchService::factory()->create();

        $this->withToken($this->serviceTokenFor($this->admin))
            ->getJson("/api/services/{$churchService->id}")
            ->assertNotFound();
    }

    private function serviceTokenFor(User $user): string
    {
        return $user->createToken('service-token', [ApiTokenAbility::ServiceUpload->value])->plainTextToken;
    }

    private function validOpenLpUpload(): UploadedFile
    {
        return OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One', 'song one@')
                ),
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::bibleHeader('Luke 15 long title', 'Luke 15:1-32')
                ),
            ]),
        );
    }
}
