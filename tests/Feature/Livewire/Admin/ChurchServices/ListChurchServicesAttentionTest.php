<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Actions\InboundEmail\ReparseInboundEmail;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Livewire\Admin\ChurchServices\ListChurchServices;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\MediaProcessingLog;
use App\Models\ServiceSection;
use App\Models\User;
use App\Queries\ReviewInboxQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithInboundEmailTestHelpers;

class ListChurchServicesAttentionTest extends TestCase
{
    use RefreshDatabase;
    use WithInboundEmailTestHelpers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['service-tracking.enabled' => true]);
        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
        $this->actingAs($this->admin);
    }

    #[Test]
    public function mixed_attention_kinds_render_as_one_service_summary_row(): void
    {
        $service = ChurchService::factory()->create([
            'date' => '2026-06-07',
            'service' => SermonService::Morning,
            'needs_review' => true,
            'pending_structure_merge_source' => 'openlp',
        ]);
        $run = MediaProcessingLog::factory()->livestream()->completed()->create([
            'church_service_id' => $service->id,
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
            'sermon_id' => null,
        ]);
        ServiceSection::factory()->count(2)->create([
            'media_processing_log_id' => $run->id,
            'needs_manual_review' => true,
        ]);
        MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'extracted_date' => '2026-06-07',
            'extracted_service' => SermonService::Morning->value,
        ]);

        Livewire::test(ListChurchServices::class)
            ->assertSee('7 Jun 2026 — Morning')
            ->assertSee('2 sections to confirm · 1 sermon segment needs choosing · 1 plan conflict to resolve · 1 service needs checking')
            ->assertSeeHtml(route('admin.services.show', $service))
            ->assertDontSee('Choose segment')
            ->assertDontSee('Mark reviewed');
    }

    #[Test]
    public function email_actions_and_diagnostics_are_available_on_the_hub(): void
    {
        $email = InboundEmail::factory()->create([
            'subject' => 'Sunday order',
            'status' => InboundEmailStatus::Pending->value,
            'body_plain' => 'Original plain text',
            'processing_metadata' => $this->processingMetadata('2026-06-07', 'morning', [
                ['title' => 'Welcome', 'type' => 'custom'],
            ]),
        ]);

        Livewire::test(ListChurchServices::class)
            ->assertSee('Sunday order')
            ->assertSee('Approve')
            ->assertSee('Edit &amp; approve', escape: false)
            ->assertSee('Re-parse')
            ->assertSee('Reject')
            ->assertSee('Original email')
            ->assertSee('Original plain text')
            ->call('rejectEmail', $email->id)
            ->assertDispatched('notify', type: 'success', message: 'Inbound email rejected.')
            ->assertDontSee('Sunday order');

        $this->assertSame(InboundEmailStatus::Rejected, $email->fresh()->status);
    }

    #[Test]
    public function approve_email_imports_the_service(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata('2026-06-07', 'morning', [
                ['position' => 1, 'type' => 'custom', 'title' => 'Welcome'],
            ]),
        ]);

        Livewire::test(ListChurchServices::class)->call('approveEmail', $email->id);

        $this->assertDatabaseHas('church_services', [
            'date' => '2026-06-07',
            'service' => SermonService::Morning->value,
        ]);
        $this->assertSame(InboundEmailStatus::Processed, $email->fresh()->status);
    }

    #[Test]
    public function reviewer_can_retain_an_uncertain_email_as_supporting_evidence(): void
    {
        $processingMetadata = $this->processingMetadata('2026-06-07', 'morning', [
            ['position' => 1, 'type' => 'songs', 'title' => 'Amazing Grace'],
        ]);
        $processingMetadata['parsing']['service_plans'][0]['content_scope'] = 'unknown';
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $processingMetadata,
        ]);

        Livewire::test(ListChurchServices::class)
            ->assertSee('Retain as evidence')
            ->call('retainEmailEvidence', $email->id)
            ->assertDispatched('notify', type: 'success', message: 'Inbound email processed: 1 evidence retained.');

        $service = ChurchService::query()->sole();
        $sourceRecord = $service->sourceRecords()->with('assertions')->sole();

        $this->assertFalse($sourceRecord->payload_complete);
        $this->assertCount(1, $sourceRecord->assertions);
        $this->assertCount(0, $service->items);
        $this->assertSame(InboundEmailStatus::Processed, $email->fresh()->status);
    }

    #[Test]
    public function edit_and_approve_email_opens_the_prefilled_editor(): void
    {
        $email = InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending->value]);

        Livewire::test(ListChurchServices::class)
            ->call('editAndApproveEmail', $email->id)
            ->assertRedirect(route('admin.services.create', ['inboundEmailId' => $email->id]));
    }

    #[Test]
    public function per_plan_edit_buttons_wire_the_plan_key_into_the_click_expression(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->multiServiceProcessingMetadata([
                ['date' => '2026-06-07', 'service' => 'morning', 'items' => [['title' => 'Welcome', 'type' => 'custom']]],
                ['date' => '2026-06-07', 'service' => 'evening', 'items' => [['title' => 'Closing hymn', 'type' => 'songs']]],
            ]),
        ]);

        $html = html_entity_decode(Livewire::test(ListChurchServices::class)->html(), ENT_QUOTES);

        $this->assertStringNotContainsString('@js(', $html, 'Blade directives are not compiled inside component tag attributes.');
        $this->assertStringContainsString("editAndApproveEmail({$email->id}, 'morning:2026-06-07')", $html);
        $this->assertStringContainsString("editAndApproveEmail({$email->id}, 'evening:2026-06-07')", $html);
    }

    #[Test]
    public function multi_service_emails_do_not_offer_the_ambiguous_whole_email_editor(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->multiServiceProcessingMetadata([
                ['date' => '2026-06-07', 'service' => 'morning', 'items' => [['title' => 'Welcome', 'type' => 'custom']]],
                ['date' => '2026-06-07', 'service' => 'evening', 'items' => [['title' => 'Closing hymn', 'type' => 'songs']]],
            ]),
        ]);

        $html = html_entity_decode(Livewire::test(ListChurchServices::class)->html(), ENT_QUOTES);

        // The plan-less editor only ever prefills the primary plan, silently dropping the evening
        // order, so a multi-service email must be edited one plan at a time.
        $this->assertStringNotContainsString("editAndApproveEmail({$email->id})", $html);
        $this->assertStringContainsString('2 service orders — edit each order separately', $html);
    }

    #[Test]
    public function edit_and_approve_email_targets_a_single_plan_of_a_multi_service_email(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->multiServiceProcessingMetadata([
                ['date' => '2026-06-07', 'service' => 'morning', 'items' => [['title' => 'Welcome', 'type' => 'custom']]],
                ['date' => '2026-06-07', 'service' => 'evening', 'items' => [['title' => 'Closing hymn', 'type' => 'songs']]],
            ]),
        ]);

        Livewire::test(ListChurchServices::class)
            ->call('editAndApproveEmail', $email->id, 'evening:2026-06-07')
            ->assertRedirect(route('admin.services.create', [
                'inboundEmailId' => $email->id,
                'planKey' => 'evening:2026-06-07',
            ]));
    }

    #[Test]
    public function reparse_email_refreshes_its_preview(): void
    {
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Failed->value,
            'body_plain' => 'Sunday 7 June 2026 Morning\nWelcome',
            'processing_metadata' => null,
        ]);

        $this->mock(ReparseInboundEmail::class, function ($mock) use ($email): void {
            $mock->shouldReceive('execute')->once()->withArgs(fn (InboundEmail $candidate): bool => $candidate->is($email))->andReturnNull();
        });

        Livewire::test(ListChurchServices::class)
            ->call('reparseEmail', $email->id)
            ->assertDispatched('notify', type: 'success', message: 'Inbound email re-parsed. Review the updated preview before approving.');
    }

    #[Test]
    public function orphan_attention_groups_offer_to_create_the_resolved_service(): void
    {
        MediaProcessingLog::factory()->livestream()->manualReviewRequired()->create([
            'extracted_date' => '2026-06-14',
            'extracted_service' => SermonService::Evening->value,
        ]);

        Livewire::test(ListChurchServices::class)
            ->assertSee('Create this service')
            ->assertSee(route('admin.services.create', ['date' => '2026-06-14', 'service' => 'evening']));
    }

    #[Test]
    public function capped_attention_copy_uses_the_true_item_total(): void
    {
        InboundEmail::factory()->count(ReviewInboxQuery::SOURCE_CAP + 1)->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => null,
        ]);

        Livewire::test(ListChurchServices::class)
            ->assertSee('Showing the newest 50 of 51 — resolve items to see older ones.');
    }

    #[Test]
    public function retired_inbox_urls_redirect_to_the_services_hub(): void
    {
        foreach ([
            'admin.services.inbox',
            'admin.services.review',
            'admin.services.inbound-emails',
            'admin.services.section-publications',
            'admin.services.processing.review.index',
        ] as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('admin.services.index'));
        }
    }

    #[Test]
    public function email_mutations_reject_non_admin_users(): void
    {
        $email = InboundEmail::factory()->create(['status' => InboundEmailStatus::Pending->value]);
        $this->actingAs(User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]));

        foreach (['approveEmail', 'retainEmailEvidence', 'editAndApproveEmail', 'reparseEmail', 'rejectEmail'] as $action) {
            Livewire::test(ListChurchServices::class)->call($action, $email->id)->assertForbidden();
        }
    }
}
