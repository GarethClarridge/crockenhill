<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Actions\SaveChurchServiceFromAdmin;
use App\Enums\ChurchServiceItemSource;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Enums\ServiceSectionType;
use App\Events\ChurchServiceCanonicalListChanged;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\InboundEmail;
use App\Models\Song;
use App\Models\User;
use App\Services\ChurchService\ChurchServiceItemSyncService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SaveChurchServiceFromAdminTest extends TestCase
{
    use RefreshDatabase;

    private SaveChurchServiceFromAdmin $action;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(SaveChurchServiceFromAdmin::class);
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_creates_a_new_church_service_with_items(): void
    {
        $song = Song::factory()->create([
            'title' => 'Blessed Assurance',
            'canonical_key' => 'blessed assurance',
        ]);

        $payload = [
            [
                'position' => 1,
                'type' => 'custom',
                'title' => 'Welcome',
                'source_title' => 'Welcome',
                'openlp_search_title' => null,
                'song_id' => null,
                'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
            ],
            [
                'position' => 2,
                'type' => 'songs',
                'title' => 'Blessed Assurance',
                'source_title' => 'Blessed Assurance',
                'openlp_search_title' => null,
                'song_id' => $song->id,
                'metadata' => ['section_type' => ServiceSectionType::Song->value, 'linked_song_canonical_key' => 'blessed assurance'],
            ],
        ];

        $result = $this->action->execute(
            validated: ['date' => '2026-05-03', 'service' => SermonService::Morning->value],
            syncPayload: $payload,
            churchService: null,
            userId: $this->admin->id,
        );

        $this->assertInstanceOf(ChurchService::class, $result);
        $this->assertDatabaseHas('church_services', [
            'date' => '2026-05-03',
            'service' => SermonService::Morning->value,
            'source' => 'manual',
            'needs_review' => false,
        ]);

        $service = ChurchService::query()->with('items')->firstOrFail();
        $this->assertCount(2, $service->items);
        $this->assertSame($this->admin->id, $service->import_metadata['manual_edit']['saved_by_user_id'] ?? null);
        $this->assertSame(2, $service->import_metadata['manual_edit']['item_count'] ?? null);
    }

    #[Test]
    public function it_updates_an_existing_church_service(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-05-10',
            'service' => SermonService::Morning,
            'source' => 'openlp',
            'original_filename' => '2026-05-10 AM.osz',
            'needs_review' => true,
            'import_metadata' => ['confidence_score' => 0.9],
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'title' => 'Old Welcome',
            'source_title' => 'Old Welcome',
            'openlp_search_title' => null,
            'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
        ]);

        $payload = [
            [
                'position' => 1,
                'type' => 'custom',
                'title' => 'New Welcome',
                'source_title' => 'New Welcome',
                'openlp_search_title' => null,
                'song_id' => null,
                'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
            ],
        ];

        $result = $this->action->execute(
            validated: ['date' => '2026-05-10', 'service' => SermonService::Morning->value],
            syncPayload: $payload,
            churchService: $service,
            userId: $this->admin->id,
        );

        $this->assertSame($service->id, $result->id);
        $service->refresh();

        $this->assertSame('manual', $service->source);
        $this->assertFalse($service->needs_review);
        // Existing metadata should be merged, not overwritten
        $this->assertSame(0.9, $service->import_metadata['confidence_score'] ?? null);
        $this->assertSame($this->admin->id, $service->import_metadata['manual_edit']['saved_by_user_id'] ?? null);
        // Original filename should be preserved
        $this->assertSame('2026-05-10 AM.osz', $service->original_filename);
    }

    #[Test]
    public function it_emits_canonical_list_changed_event_when_items_change(): void
    {
        Event::fake([ChurchServiceCanonicalListChanged::class]);

        $payload = [[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Welcome',
            'source_title' => 'Welcome',
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
        ]];

        $result = $this->action->execute(
            validated: ['date' => '2026-06-01', 'service' => SermonService::Morning->value],
            syncPayload: $payload,
            churchService: null,
            userId: $this->admin->id,
        );

        Event::assertDispatched(
            ChurchServiceCanonicalListChanged::class,
            fn (ChurchServiceCanonicalListChanged $event): bool => $event->churchServiceId === $result->id
                && $event->source === 'manual'
        );
        Event::assertDispatchedTimes(ChurchServiceCanonicalListChanged::class, 1);
    }

    #[Test]
    public function it_does_not_emit_canonical_event_when_nothing_changes(): void
    {
        Event::fake([ChurchServiceCanonicalListChanged::class]);

        $service = ChurchService::factory()->create([
            'date' => '2026-06-08',
            'service' => SermonService::Morning,
            'source' => 'manual',
        ]);

        ChurchServiceItem::factory()->create([
            'church_service_id' => $service->id,
            'position' => 1,
            'type' => 'custom',
            'source' => 'manual',
            'title' => 'Welcome',
            'source_title' => 'Welcome',
            'openlp_search_title' => null,
            'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
        ]);

        // Save the same item again — no change
        $payload = [[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Welcome',
            'source_title' => 'Welcome',
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
        ]];

        $this->action->execute(
            validated: ['date' => '2026-06-08', 'service' => SermonService::Morning->value],
            syncPayload: $payload,
            churchService: $service,
            userId: $this->admin->id,
        );

        Event::assertNotDispatched(ChurchServiceCanonicalListChanged::class);
    }

    #[Test]
    public function it_marks_inbound_email_as_processed_when_provided(): void
    {
        $inboundEmail = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
        ]);

        $payload = [[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Welcome',
            'source_title' => 'Welcome',
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
        ]];

        $churchService = $this->action->execute(
            validated: ['date' => '2026-06-15', 'service' => SermonService::Morning->value],
            syncPayload: $payload,
            churchService: null,
            userId: $this->admin->id,
            inboundEmailId: $inboundEmail->id,
        );

        $inboundEmail->refresh();
        $this->assertNotSame(InboundEmailStatus::Pending->value, $inboundEmail->status->value);
        $this->assertSame(ChurchServiceItemSource::Email->value, $churchService->source);
        $this->assertSame(
            [ChurchServiceItemSource::Email],
            $churchService->items->firstOrFail()->provenanceSources(),
        );
    }

    #[Test]
    public function it_does_not_fail_when_inbound_email_id_does_not_exist(): void
    {
        $payload = [[
            'position' => 1,
            'type' => 'custom',
            'title' => 'Welcome',
            'source_title' => 'Welcome',
            'openlp_search_title' => null,
            'song_id' => null,
            'metadata' => ['section_type' => ServiceSectionType::Welcome->value],
        ]];

        $result = $this->action->execute(
            validated: ['date' => '2026-07-01', 'service' => SermonService::Morning->value],
            syncPayload: $payload,
            churchService: null,
            userId: $this->admin->id,
            inboundEmailId: 99999,
        );

        $this->assertInstanceOf(ChurchService::class, $result);
        $this->assertDatabaseCount('church_services', 1);
    }

    #[Test]
    public function it_throws_when_items_constraint_violation_escapes_sync(): void
    {
        $churchService = ChurchService::factory()->create();

        $mock = $this->mock(ChurchServiceItemSyncService::class);
        $previous = new \PDOException('Duplicate entry for key "church_service_items_active_position_unique"');
        $mock->shouldReceive('sync')
            ->once()
            ->andThrow(new UniqueConstraintViolationException('default', 'INSERT INTO ...', [], $previous));

        $this->app->instance(ChurchServiceItemSyncService::class, $mock);
        $action = app(SaveChurchServiceFromAdmin::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ordering conflict');

        $action->execute(
            validated: [
                'date' => '2026-04-23',
                'service' => SermonService::Morning->value,
            ],
            syncPayload: [],
            churchService: $churchService,
            userId: $this->admin->id,
        );
    }
}
