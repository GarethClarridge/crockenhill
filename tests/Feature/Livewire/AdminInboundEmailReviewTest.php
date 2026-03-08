<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Contracts\OosEmailItemExtractor;
use App\Data\OosEmailItemExtractionResult;
use App\Enums\InboundEmailStatus;
use App\Enums\SermonService;
use App\Livewire\Admin\ChurchServices\ManageChurchService;
use App\Livewire\Admin\ChurchServices\ReviewInboundEmails;
use App\Models\ChurchService;
use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminInboundEmailReviewTest extends TestCase
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
    public function pending_and_failed_inbound_emails_are_displayed_in_the_review_queue(): void
    {
        $this->actingAs($this->admin);

        $pendingEmail = InboundEmail::factory()->create([
            'subject' => 'Pending service plan',
            'status' => InboundEmailStatus::PENDING->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-08',
                resolvedService: SermonService::MORNING->value,
                items: [
                    ['type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ['type' => 'songs', 'title' => 'Living Hope', 'metadata' => null],
                ],
            ),
        ]);

        $failedEmail = InboundEmail::factory()->create([
            'subject' => 'Failed email',
            'status' => InboundEmailStatus::FAILED->value,
            'processing_metadata' => array_replace_recursive(
                $this->processingMetadata(
                    resolvedDate: '2026-06-15',
                    resolvedService: SermonService::EVENING->value,
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
            'status' => InboundEmailStatus::PROCESSED->value,
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
            'status' => InboundEmailStatus::PENDING->value,
        ]);

        $component = Livewire::test(ReviewInboundEmails::class)
            ->call('approve', $email->id);

        $service = ChurchService::query()->firstOrFail();

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertSame('email', $service->source);
        $this->assertFalse($service->needs_review);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::PROCESSED, $email->status);
        $this->assertSame('direct_approve', $email->processing_metadata['review']['mode'] ?? null);
        $this->assertSame($this->admin->id, $email->processing_metadata['review']['approved_by_user_id'] ?? null);
    }

    #[Test]
    public function approve_action_uses_stored_parse_data_before_falling_back_to_reparsing(): void
    {
        $this->actingAs($this->admin);

        $this->bindFailingExtractor();

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::PENDING->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-29',
                resolvedService: SermonService::MORNING->value,
                items: [
                    ['position' => 1, 'type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ['position' => 2, 'type' => 'custom', 'title' => 'Sermon', 'metadata' => ['email_type' => 'sermon']],
                ],
            ),
        ]);

        $component = Livewire::test(ReviewInboundEmails::class)
            ->call('approve', $email->id);

        $service = ChurchService::query()->firstOrFail();

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertSame('email', $service->source);
        $this->assertFalse($service->needs_review);
        $this->assertSame(['Welcome', 'Sermon'], $service->items()->orderBy('position')->pluck('title')->all());
    }

    #[Test]
    public function reject_action_marks_the_email_as_rejected(): void
    {
        $this->actingAs($this->admin);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::PENDING->value,
        ]);

        Livewire::test(ReviewInboundEmails::class)
            ->call('reject', $email->id)
            ->assertDispatched('notify', type: 'success', message: 'Inbound email rejected.');

        $email->refresh();

        $this->assertSame(InboundEmailStatus::REJECTED, $email->status);
        $this->assertSame($this->admin->id, $email->processing_metadata['review']['rejected_by_user_id'] ?? null);
    }

    #[Test]
    public function edit_and_approve_redirects_to_the_prefilled_manual_form(): void
    {
        $this->actingAs($this->admin);

        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::PENDING->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-06-29',
                resolvedService: SermonService::MORNING->value,
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
            'status' => InboundEmailStatus::PENDING->value,
            'processing_metadata' => $this->processingMetadata(
                resolvedDate: '2026-07-06',
                resolvedService: SermonService::MORNING->value,
                items: [
                    ['type' => 'custom', 'title' => 'Welcome', 'metadata' => ['email_type' => 'welcome']],
                    ['type' => 'custom', 'title' => 'Opening Prayer', 'metadata' => ['email_type' => 'prayer']],
                ],
            ),
        ]);

        $component = Livewire::test(ManageChurchService::class, ['inboundEmailId' => $email->id])
            ->assertSet('date', '2026-07-06')
            ->assertSet('service', SermonService::MORNING->value)
            ->assertSet('items.0.title', 'Welcome')
            ->assertSet('items.1.title', 'Opening Prayer')
            ->call('save');

        $service = ChurchService::query()->firstOrFail();

        $component->assertRedirect(route('admin.services.show', $service));

        $this->assertSame('manual', $service->source);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::PROCESSED, $email->status);
        $this->assertSame('manual_edit', $email->processing_metadata['review']['mode'] ?? null);
        $this->assertSame($service->id, $email->processing_metadata['imported_church_service_id'] ?? null);
    }

    #[Test]
    public function non_admin_cannot_access_the_inbound_email_review_component(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        Livewire::test(ReviewInboundEmails::class)->assertForbidden();
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function processingMetadata(string $resolvedDate, string $resolvedService, array $items): array
    {
        return [
            'parsing' => [
                'confidence_score' => 0.40,
                'warnings' => ['Needs manual review.'],
                'resolved_date' => $resolvedDate,
                'resolved_service' => $resolvedService,
                'items' => $items,
            ],
        ];
    }

    private function bindExtractor(OosEmailItemExtractionResult $result): void
    {
        $this->app->bind(OosEmailItemExtractor::class, fn () => new class($result) implements OosEmailItemExtractor
        {
            public function __construct(
                private readonly OosEmailItemExtractionResult $result,
            ) {}

            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                return $this->result;
            }
        });
    }

    private function bindFailingExtractor(): void
    {
        $this->app->bind(OosEmailItemExtractor::class, fn () => new class implements OosEmailItemExtractor
        {
            public function extract(string $subject, string $body): OosEmailItemExtractionResult
            {
                throw new \RuntimeException('Stored parse data should have been used instead of reparsing.');
            }
        });
    }
}
