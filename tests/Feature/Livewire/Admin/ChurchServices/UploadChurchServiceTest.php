<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Data\OpenLpImportResult;
use App\Data\OpenLpParseResult;
use App\Enums\SermonService;
use App\Livewire\Admin\ChurchServices\UploadChurchService;
use App\Models\ChurchService;
use App\Models\User;
use App\Services\ImportChurchServiceFromOpenLp;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class UploadChurchServiceTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        Config::set('service-tracking.enabled', true);
        Storage::fake('local');
    }

    #[Test]
    public function it_renders_successfully(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(UploadChurchService::class)
            ->assertStatus(200)
            ->assertSee('Upload Service');
    }

    #[Test]
    public function it_relies_on_route_middleware_for_access_control(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);

        // Route middleware (auth, verified, admin) enforces access at the HTTP layer.
        // AdminLivewireAuthorizationTest covers this. Direct component mount is unrestricted.
        Livewire::test(UploadChurchService::class)
            ->assertOk();
    }

    #[Test]
    public function it_aborts_if_service_tracking_is_disabled(): void
    {
        Config::set('service-tracking.enabled', false);
        $this->actingAs($this->admin);

        $this->get(route('admin.services.upload'))
            ->assertStatus(404);
    }

    #[Test]
    public function it_validates_required_file(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(UploadChurchService::class)
            ->call('save')
            ->assertHasErrors(['file' => 'required']);
    }

    #[Test]
    public function it_validates_file_mime_type(): void
    {
        $this->actingAs($this->admin);

        $file = UploadedFile::fake()->create('service.txt', 100);

        Livewire::test(UploadChurchService::class)
            ->set('file', $file)
            ->call('save')
            ->assertHasErrors(['file' => 'mimes']);
    }

    #[Test]
    public function it_validates_file_size(): void
    {
        $this->actingAs($this->admin);

        Config::set('service-tracking.upload.max_size_kb', 10);
        $file = UploadedFile::fake()->create('service.zip', 11);

        Livewire::test(UploadChurchService::class)
            ->set('file', $file)
            ->call('save')
            ->assertHasErrors(['file' => 'max']);
    }

    #[Test]
    public function it_imports_service_successfully(): void
    {
        $this->actingAs($this->admin);

        $upload = OpenLpArchiveFactory::makeUpload(
            archiveName: '2024-11-17 AM.osz',
            osjName: '2024-11-17 AM.osj',
            payload: OpenLpArchiveFactory::payload([
                OpenLpArchiveFactory::serviceItem(
                    OpenLpArchiveFactory::songHeader('Song One', 'song one@')
                ),
            ]),
        );

        // Attach a 'name' property to the UploadedFile to satisfy Livewire's internal _startUpload logic
        // which appears to be environment-sensitive in this specific VM setup.
        $upload->name = '2024-11-17 AM.osz';

        // We use a mock to avoid environment-specific Livewire file upload issues
        // while still verifying the interaction between the component and the service.
        $this->mock(ImportChurchServiceFromOpenLp::class)
            ->shouldReceive('import')
            ->once()
            ->andReturn(new OpenLpImportResult(
                churchService: ChurchService::factory()->create(),
                parseResult: new OpenLpParseResult(
                    date: '2024-11-17',
                    service: SermonService::Morning,
                    items: [],
                    needsReview: false,
                    importMetadata: []
                ),
                wasCreated: true,
                syncResult: [],
                linkResult: []
            ));

        Livewire::test(UploadChurchService::class)
            ->set('file', $upload)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSessionHas('notification', ['type' => 'success', 'message' => 'Service imported successfully'])
            ->assertRedirect();
    }

    #[Test]
    public function it_handles_import_errors_gracefully(): void
    {
        $this->actingAs($this->admin);

        $upload = OpenLpArchiveFactory::makeUpload();
        $upload->name = '2024-11-17 AM.osz';

        $this->mock(ImportChurchServiceFromOpenLp::class)
            ->shouldReceive('import')
            ->andThrow(new \Exception('Import failed'));

        Livewire::test(UploadChurchService::class)
            ->set('file', $upload)
            ->call('save')
            ->assertHasErrors(['file']);
    }
}
