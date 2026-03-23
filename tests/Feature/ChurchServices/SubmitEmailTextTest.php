<?php

declare(strict_types=1);

namespace Tests\Feature\ChurchServices;

use App\Enums\InboundEmailStatus;
use App\Jobs\ProcessInboundOosEmail;
use App\Livewire\Admin\ChurchServices\SubmitEmailText;
use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubmitEmailTextTest extends TestCase
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
    public function page_renders_the_submit_email_text_component(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.services.submit-email'))
            ->assertSeeLivewire(SubmitEmailText::class)
            ->assertStatus(200);
    }

    #[Test]
    public function valid_body_creates_an_inbound_email_and_dispatches_the_job(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        $body = str_repeat('Order of service content. ', 5);

        Livewire::test(SubmitEmailText::class)
            ->set('bodyPlain', $body)
            ->call('submit')
            ->assertSet('submitted', true);

        $this->assertDatabaseCount('inbound_emails', 1);

        $inboundEmail = InboundEmail::query()->firstOrFail();

        $this->assertSame($body, $inboundEmail->body_plain);
        $this->assertSame(InboundEmailStatus::PENDING, $inboundEmail->status);
        $this->assertStringStartsWith('manual-', $inboundEmail->message_id);
        $this->assertStringEndsWith('@admin.crockenhill.org', $inboundEmail->message_id);

        Queue::assertPushed(ProcessInboundOosEmail::class);
    }

    #[Test]
    public function optional_from_and_subject_are_stored_when_provided(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        Livewire::test(SubmitEmailText::class)
            ->set('from', 'pastor@church.org')
            ->set('subject', 'Order of Service 22 Feb 2026')
            ->set('bodyPlain', str_repeat('Service item content here. ', 5))
            ->call('submit')
            ->assertSet('submitted', true);

        $inboundEmail = InboundEmail::query()->firstOrFail();

        $this->assertSame('pastor@church.org', $inboundEmail->from);
        $this->assertSame('Order of Service 22 Feb 2026', $inboundEmail->subject);
    }

    #[Test]
    public function default_from_and_subject_are_used_when_optional_fields_are_blank(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        Livewire::test(SubmitEmailText::class)
            ->set('bodyPlain', str_repeat('Service item content here. ', 5))
            ->call('submit');

        $inboundEmail = InboundEmail::query()->firstOrFail();

        $this->assertSame('admin@manual-entry', $inboundEmail->from);
        $this->assertSame('Manual entry', $inboundEmail->subject);
    }

    #[Test]
    public function empty_body_fails_validation(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(SubmitEmailText::class)
            ->set('bodyPlain', '')
            ->call('submit')
            ->assertHasErrors(['bodyPlain' => 'required']);

        $this->assertDatabaseCount('inbound_emails', 0);
    }

    #[Test]
    public function body_under_twenty_characters_fails_validation(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(SubmitEmailText::class)
            ->set('bodyPlain', 'Too short')
            ->call('submit')
            ->assertHasErrors(['bodyPlain' => 'min']);

        $this->assertDatabaseCount('inbound_emails', 0);
    }

    #[Test]
    public function non_admin_cannot_access_the_submit_email_component(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.services.submit-email'))
            ->assertForbidden();
    }
}
