<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Data\OosEmailItemExtractionResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Livewire\Admin\ChurchServices\ManageChurchService;
use App\Livewire\Admin\ChurchServices\ReviewInboundEmails;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\WithInboundEmailTestHelpers;

class AdminInboundEmailReviewTest extends TestCase
{
    use RefreshDatabase;
    use WithInboundEmailTestHelpers;

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
    public function pending_and_failed_inbound_emails_are_displayed_in_the_review_queue(): void
    {
        $this->actingAs($this->admin);

        $pendingEmail = InboundEmail::factory()->create([
            'subject' => 'Pending service plan',
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-08',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ['type' => 'songs', 'title' => 'Living Hope', 'metadata' => null],
                ],
            ),
        ]);

        $failedEmail = InboundEmail::factory()->create([
            'subject' => 'Failed email',
            'status' => InboundEmailStatus::Failed->value,
            'processing_metadata' => array_replace_recursive(
                $this->processingMetadata(
                    resolvedDate: '2026-06-15',
                    resolvedService: SermonService::Evening->value,
                    items: [],
                ),
                [
                    'failure' => [
                        'message' => 'Parser exploded',
                    ],
                ],
            ),
        ]);

        $processedEmail = InboundEmail::factory()->create([
            'subject' => 'Already imported',
            'status' => InboundEmailStatus::Processed->value,
        ]);

        Livewire::test(ReviewInboundEmails::class)
            ->assertSee($pendingEmail->subject)
            ->assertSee($failedEmail->subject)
            ->assertDontSee($processedEmail->subject)
            ->assertSee('Welcome')
            ->assertSee('Living Hope')
            ->assertSee('Parser exploded');
    }

    #[Test]
    public function original_email_panel_shows_plain_text_and_parser_metadata(): void
    {
        $this->actingAs($this->admin);

        $email = InboundEmail::factory()->create([
            'subject' => 'Plain text review email',
            'body_plain' => "Welcome\nSong One\nPrayer",
            'body_html' => null,
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-08',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                ],
            ),
        ]);

        Livewire::test(ReviewInboundEmails::class)
            ->assertSee($email->subject)
            ->assertSeeText('Original email')
            ->assertSeeText('Welcome')
            ->assertSeeText('Song One')
            ->assertSeeText('Prayer')
            ->assertSeeText('Parser Warnings')
            ->assertSeeText('Raw Parser Metadata')
            ->assertSeeText('No HTML body stored.')
            ->assertSeeText('"resolved_date": "2026-06-08"');
    }

    #[Test]
    public function html_email_preview_is_sanitized_before_it_is_rendered(): void
    {
        $this->actingAs($this->admin);

        InboundEmail::factory()->create([
            'subject' => 'HTML review email',
            'body_plain' => null,
            'body_html' => <<<'HTML'
                <p>Welcome <strong>team</strong></p>
                <p><a href="https://example.com" onclick="alert('bad')">Read more</a></p>
                <script>alert("owned")</script>
                <a href="javascript:alert('bad')">Unsafe link</a>
                <svg><circle /></svg>
            HTML,
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-08',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                ],
            ),
        ]);

        Livewire::test(ReviewInboundEmails::class)
            ->assertSeeText('HTML Preview')
            ->assertSeeHtml('href="https://example.com"')
            ->assertSeeHtml('rel="noopener noreferrer nofollow"')
            ->assertSeeHtml('target="_blank"')
            ->assertDontSeeHtml('<script>alert("owned")</script>')
            ->assertDontSeeHtml('onclick=')
            ->assertDontSeeHtml('javascript:alert')
            ->assertDontSeeHtml('<svg>');
    }

    #[Test]
    public function missing_email_bodies_degrade_gracefully_in_the_review_ui(): void
    {
        $this->actingAs($this->admin);

        InboundEmail::factory()->create([
            'subject' => 'No bodies stored',
            'body_plain' => null,
            'body_html' => null,
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => null,
        ]);

        Livewire::test(ReviewInboundEmails::class)
            ->assertSeeText('No plain-text body stored.')
            ->assertSeeText('No HTML body stored.')
            ->assertSeeText('No parser warnings recorded.')
            ->assertSeeText('No parser metadata stored yet.');
    }

    #[Test]
    public function approve_action_creates_a_service_from_the_reviewed_email(): void
    {
        $this->actingAs($this->admin);

        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'sermon', 'title' => 'Sermon'],
            ],
            confidence: 0.20,
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-06-22 AM',
            'body_plain' => "Welcome\nSermon",
            'status' => InboundEmailStatus::Pending->value,
        ]);

        $component = Livewire::test(ReviewInboundEmails::class)
            ->call('approve', $email->id);

        $service = ChurchService::query()
            ->where('date', '2026-06-22')
            ->where('service', SermonService::Morning->value)
            ->sole();

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertSame('email', $service->source);
        $this->assertFalse($service->needs_review);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Processed, $email->status);
        $this->assertSame($service->id, $email->processing_metadata['imported_church_service_id'] ?? null);
        $this->assertSame('direct_approve', $email->processing_metadata['review']['mode'] ?? null);
        $this->assertSame($this->admin->id, $email->processing_metadata['review']['approved_by_user_id'] ?? null);
    }

    #[Test]
    public function approve_action_uses_stored_parse_data_before_falling_back_to_reparsing(): void
    {
        $this->actingAs($this->admin);

        $this->bindFailingExtractor();

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-29',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ['position' => 2, 'type' => 'custom', 'title' => 'Sermon', 'metadata' => ['email_type' => 'sermon']],
                ],
            ),
        ]);

        $component = Livewire::test(ReviewInboundEmails::class)
            ->call('approve', $email->id);

        $service = ChurchService::query()
            ->where('date', '2026-06-29')
            ->where('service', SermonService::Morning->value)
            ->sole();

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertSame('email', $service->source);
        $this->assertFalse($service->needs_review);
        $this->assertSame(['Welcome', 'Sermon'], $service->items()->orderBy('position')->pluck('title')->all());
    }

    #[Test]
    public function reparse_action_updates_stored_parsing_metadata_and_does_not_import(): void
    {
        $this->actingAs($this->admin);
        $this->travelTo(Carbon::parse('2026-03-12 11:30:00'));
        $baselineServiceCount = ChurchService::count();

        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Call to Worship'],
                ['type' => 'sermon', 'title' => 'Sermon'],
            ],
            confidence: 0.97,
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-06-29 AM',
            'body_plain' => "Call to Worship\nSermon",
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => array_replace_recursive(
                $this->processingMetadata(
                    resolvedDate: '2026-06-22',
                    resolvedService: SermonService::Morning->value,
                    items: [
                        ['position' => 1, 'type' => 'custom', 'title' => 'Old Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ],
                ),
                [
                    'review' => [
                        'notes' => 'Keep this note',
                    ],
                ],
            ),
        ]);

        Livewire::test(ReviewInboundEmails::class)
            ->call('reparse', $email->id)
            ->assertDispatched('notify', type: 'success', message: 'Inbound email re-parsed. Review the updated preview before approving.')
            ->assertSeeText('Call to Worship')
            ->assertSeeText('Sermon')
            ->assertSeeText('Ready to approve')
            ->assertSeeText('12 Mar 2026 11:30');

        $email->refresh();

        $this->assertSame(InboundEmailStatus::Pending, $email->status);
        $this->assertSame('2026-03-12T11:30:00+00:00', $email->processing_metadata['reparsed_at'] ?? null);
        $this->assertSame('Keep this note', $email->processing_metadata['review']['notes'] ?? null);
        $this->assertSame('2026-06-29', $email->processing_metadata['parsing']['resolved_date'] ?? null);
        $this->assertSame('morning', $email->processing_metadata['parsing']['resolved_service'] ?? null);
        $this->assertSame(['Call to Worship', 'Sermon'], collect($email->processing_metadata['parsing']['items'] ?? [])->pluck('title')->all());
        $this->assertArrayNotHasKey('imported_church_service_id', $email->processing_metadata ?? []);
        $this->assertSame($baselineServiceCount, ChurchService::count());

        $this->travelBack();
    }

    #[Test]
    public function failed_emails_can_be_reparsed_without_duplication_or_auto_import(): void
    {
        $this->actingAs($this->admin);
        $baselineServiceCount = ChurchService::count();

        $this->bindExtractor(new OosEmailItemExtractionResult(
            items: [
                ['type' => 'welcome', 'title' => 'Welcome'],
                ['type' => 'prayer', 'title' => 'Opening Prayer'],
            ],
            confidence: 0.94,
        ));

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-07-06 PM',
            'body_plain' => "Welcome\nOpening Prayer",
            'status' => InboundEmailStatus::Failed->value,
            'processing_metadata' => array_replace_recursive(
                $this->processingMetadata(
                    resolvedDate: '2026-07-06',
                    resolvedService: SermonService::Morning->value,
                    items: [],
                ),
                [
                    'failure' => [
                        'message' => 'Parser exploded',
                    ],
                ],
            ),
        ]);

        Livewire::test(ReviewInboundEmails::class)
            ->call('reparse', $email->id)
            ->assertSeeText('Opening Prayer')
            ->assertSeeText('Evening')
            ->assertSeeText('Ready to approve')
            ->assertDontSeeText('Parser exploded');

        $email->refresh();

        $this->assertSame(InboundEmailStatus::Pending, $email->status);
        $this->assertNull($email->processing_metadata['failure'] ?? null);
        $this->assertSame('evening', $email->processing_metadata['parsing']['resolved_service'] ?? null);
        $this->assertCount(2, $email->processing_metadata['parsing']['items'] ?? []);
        $this->assertArrayNotHasKey('imported_church_service_id', $email->processing_metadata ?? []);
        $this->assertSame($baselineServiceCount, ChurchService::count());
    }

    #[Test]
    public function reparse_handles_parser_failure_gracefully_and_leaves_email_unchanged(): void
    {
        $this->actingAs($this->admin);
        $baselineServiceCount = ChurchService::count();

        $this->bindFailingExtractor();

        $email = InboundEmail::factory()->create([
            'subject' => 'Order of Service - 2026-06-29 AM',
            'body_plain' => "Welcome\nSermon",
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-29',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                ],
            ),
        ]);

        Livewire::test(ReviewInboundEmails::class)
            ->call('reparse', $email->id)
            ->assertDispatched('notify', type: 'error', message: 'Unable to re-parse this inbound email right now.');

        $email->refresh();

        $this->assertSame(InboundEmailStatus::Pending, $email->status);
        $this->assertNull($email->processing_metadata['reparsed_at'] ?? null);
        $this->assertSame('2026-06-29', $email->processing_metadata['parsing']['resolved_date'] ?? null);
        $this->assertSame('Welcome', $email->processing_metadata['parsing']['items'][0]['title'] ?? null);
        $this->assertArrayNotHasKey('imported_church_service_id', $email->processing_metadata ?? []);
        $this->assertSame($baselineServiceCount, ChurchService::count());
    }

    #[Test]
    public function reject_action_marks_the_email_as_rejected(): void
    {
        $this->actingAs($this->admin);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
        ]);

        Livewire::test(ReviewInboundEmails::class)
            ->call('reject', $email->id)
            ->assertDispatched('notify', type: 'success', message: 'Inbound email rejected.');

        $email->refresh();

        $this->assertSame(InboundEmailStatus::Rejected, $email->status);
        $this->assertSame($this->admin->id, $email->processing_metadata['review']['rejected_by_user_id'] ?? null);
    }

    #[Test]
    public function edit_and_approve_redirects_to_the_prefilled_manual_form(): void
    {
        $this->actingAs($this->admin);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-29',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                ],
            ),
        ]);

        Livewire::test(ReviewInboundEmails::class)
            ->call('editAndApprove', $email->id)
            ->assertRedirect(route('admin.services.create', ['inboundEmailId' => $email->id]));
    }

    #[Test]
    public function manual_service_form_prefills_from_an_inbound_email_and_marks_it_processed_on_save(): void
    {
        $this->actingAs($this->admin);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::Pending->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-07-06',
                resolvedService: SermonService::Morning->value,
                items: [
                    ['type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ['type' => 'custom', 'title' => 'Opening Prayer', 'metadata' => ['email_type' => 'prayer']],
                ],
            ),
        ]);

        $component = Livewire::test(ManageChurchService::class, ['inboundEmailId' => $email->id])
            ->assertSet('form.date', '2026-07-06')
            ->assertSet('form.service', SermonService::Morning->value)
            ->assertSet('form.items.0.title', 'Welcome')
            ->assertSet('form.items.1.title', 'Opening Prayer')
            ->call('save');

        $service = ChurchService::query()
            ->where('date', '2026-07-06')
            ->where('service', SermonService::Morning->value)
            ->sole();

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertSame('manual', $service->source);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::Processed, $email->status);
        $this->assertSame('manual_edit', $email->processing_metadata['review']['mode'] ?? null);
        $this->assertSame($service->id, $email->processing_metadata['imported_church_service_id'] ?? null);
    }
}
