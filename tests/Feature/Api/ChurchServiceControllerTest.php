<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ApiTokenAbility;
use App\Enums\SermonService;
use App\Models\ChurchService;
use App\Models\ChurchServiceItem;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            'service' => SermonService::MORNING->value,
            'source' => 'openlp',
        ]);
        $this->assertDatabaseCount('church_service_items', 2);
    }

    #[Test]
    public function test_upload_links_song_items_to_canonical_song_catalog(): void
    {
        $song = Song::factory()->create([
            'canonical_key' => 'song one@',
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
            'service' => SermonService::MORNING,
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

    private function serviceTokenFor(User $user): string
    {
        return $user->createToken('service-token', [ApiTokenAbility::SERVICE_UPLOAD->value])->plainTextToken;
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
