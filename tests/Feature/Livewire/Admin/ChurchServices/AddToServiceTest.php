<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Data\OpenLpImportResult;
use App\Data\OpenLpParseResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Jobs\ProcessInboundOosEmail;
use App\Livewire\Admin\ChurchServices\AddToService;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\User;
use App\Services\ChurchService\ImportChurchServiceFromOpenLp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class AddToServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['service-tracking.enabled' => true]);
        Storage::fake('local');

        $this->admin = User::factory()->admin()->create([
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function add_page_defaults_to_the_plan_intent_and_can_deep_link_to_recording(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.services.add'))
            ->assertOk()
            ->assertSeeLivewire(AddToService::class)
            ->assertSeeText('Add to service')
            ->assertSeeText('Upload an OpenLP file or paste an email');

        $this->get(route('admin.services.add', ['intent' => 'recording']))
            ->assertOk()
            ->assertSeeText('Upload a recording')
            ->assertSee(route('admin.services.upload-recording'));
    }

    #[Test]
    public function it_keeps_service_context_when_switching_to_the_recording_uploader(): void
    {
        $churchService = ChurchService::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(AddToService::class, ['churchServiceId' => $churchService->id])
            ->assertSeeHtml('churchServiceId='.$churchService->id)
            ->assertSeeHtml('intent=recording')
            ->set('intent', 'recording')
            ->assertSeeHtml(route('admin.services.upload-recording', ['churchServiceId' => $churchService->id]));
    }

    #[Test]
    public function an_openlp_file_uses_the_existing_import_path(): void
    {
        $this->actingAs($this->admin);

        $churchService = ChurchService::factory()->create();
        $upload = OpenLpArchiveFactory::makeUpload();
        $upload->name = '2024-11-17 AM.osz';

        $this->mock(ImportChurchServiceFromOpenLp::class)
            ->shouldReceive('import')
            ->once()
            ->andReturn(new OpenLpImportResult(
                churchService: $churchService,
                parseResult: new OpenLpParseResult(
                    date: '2024-11-17',
                    service: SermonService::Morning,
                    items: [],
                    needsReview: false,
                    importMetadata: [],
                ),
                wasCreated: true,
                syncResult: [],
                linkResult: [],
            ));

        Livewire::test(AddToService::class)
            ->set('file', $upload)
            ->call('importPlan')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.services.show', $churchService));
    }

    #[Test]
    public function pasted_email_text_uses_the_existing_inbound_email_path(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        $body = str_repeat('Order of service content. ', 5);

        Livewire::test(AddToService::class)
            ->set('from', 'pastor@church.org')
            ->set('subject', 'Order of Service 22 Feb 2026')
            ->set('bodyPlain', $body)
            ->call('importPlan')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $inboundEmail = InboundEmail::query()->firstOrFail();

        $this->assertSame('pastor@church.org', $inboundEmail->from);
        $this->assertSame('Order of Service 22 Feb 2026', $inboundEmail->subject);
        $this->assertSame($body, $inboundEmail->body_plain);
        $this->assertSame(InboundEmailStatus::Pending, $inboundEmail->status);
        Queue::assertPushed(ProcessInboundOosEmail::class, 1);
    }

    #[Test]
    public function blank_or_ambiguous_plan_input_is_rejected(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(AddToService::class)
            ->call('importPlan')
            ->assertHasErrors(['planInput']);

        $upload = OpenLpArchiveFactory::makeUpload();
        $upload->name = '2024-11-17 AM.osz';

        Livewire::test(AddToService::class)
            ->set('file', $upload)
            ->set('bodyPlain', str_repeat('Order of service. ', 3))
            ->call('importPlan')
            ->assertHasErrors(['planInput']);
    }

    #[Test]
    public function openlp_file_type_and_size_are_validated(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(AddToService::class)
            ->set('file', UploadedFile::fake()->create('service.txt', 5))
            ->call('importPlan')
            ->assertHasErrors(['file' => 'mimes']);

        config(['service-tracking.upload.max_size_kb' => 10]);

        Livewire::test(AddToService::class)
            ->set('file', UploadedFile::fake()->create('service.zip', 11))
            ->call('importPlan')
            ->assertHasErrors(['file' => 'max']);
    }

    #[Test]
    public function openlp_import_errors_are_shown_on_the_form(): void
    {
        $this->actingAs($this->admin);

        $upload = OpenLpArchiveFactory::makeUpload();
        $upload->name = '2024-11-17 AM.osz';

        $this->mock(ImportChurchServiceFromOpenLp::class)
            ->shouldReceive('import')
            ->once()
            ->andThrow(new \RuntimeException('Import failed'));

        Livewire::test(AddToService::class)
            ->set('file', $upload)
            ->call('importPlan')
            ->assertHasErrors(['file' => 'Unable to import this file. Please verify it is a valid OpenLP archive.']);
    }

    #[Test]
    public function email_defaults_and_length_validation_are_preserved(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        Livewire::test(AddToService::class)
            ->set('bodyPlain', 'Too short')
            ->call('importPlan')
            ->assertHasErrors(['bodyPlain' => 'min']);

        Livewire::test(AddToService::class)
            ->set('bodyPlain', str_repeat('Service item content here. ', 5))
            ->call('importPlan')
            ->assertHasNoErrors();

        $inboundEmail = InboundEmail::query()->firstOrFail();

        $this->assertSame('admin@manual-entry', $inboundEmail->from);
        $this->assertSame('Manual entry', $inboundEmail->subject);
    }

    #[Test]
    public function non_admin_cannot_import_a_plan(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        Livewire::test(AddToService::class)
            ->call('importPlan')
            ->assertForbidden();
    }

    #[Test]
    public function retired_intake_urls_redirect_to_the_plan_intent(): void
    {
        $this->actingAs($this->admin);

        $destination = route('admin.services.add', ['intent' => 'plan']);

        $this->get(route('admin.services.upload'))
            ->assertStatus(302)
            ->assertRedirect($destination);

        $this->get(route('admin.services.submit-email'))
            ->assertStatus(302)
            ->assertRedirect($destination);
    }

    #[Test]
    public function add_page_is_disabled_with_service_tracking_but_recording_upload_remains_available(): void
    {
        config(['service-tracking.enabled' => false]);

        $this->actingAs($this->admin);

        $this->get(route('admin.services.add'))->assertNotFound();
        $this->get(route('admin.services.upload-recording'))->assertOk();
    }
}
