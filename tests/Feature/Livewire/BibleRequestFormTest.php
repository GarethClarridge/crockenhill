<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\PageArea;
use App\Livewire\Christ\BibleRequestForm;
use App\Mail\BibleRequest;
use App\Models\Page;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BibleRequestFormTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('bible-request|127.0.0.1');

        Page::factory()->create([
            'slug' => 'free-bible',
            'heading' => 'Free Bibles',
            'area' => PageArea::Christ->value,
        ]);
    }

    // ── page rendering ────────────────────────────────────────────────────

    #[Test]
    public function free_bibles_page_is_publicly_accessible(): void
    {
        $this->get('/christ/free-bible')
            ->assertOk()
            ->assertSee('Free Bible');
    }

    #[Test]
    public function free_bibles_page_renders_the_livewire_component(): void
    {
        $this->get('/christ/free-bible')
            ->assertOk()
            ->assertSeeLivewire(BibleRequestForm::class);
    }

    // ── form validation ───────────────────────────────────────────────────

    #[Test]
    public function submit_requires_name_and_address(): void
    {
        Livewire::test(BibleRequestForm::class)
            ->call('submit')
            ->assertHasErrors(['name', 'address']);
    }

    #[Test]
    public function submit_validates_email_format(): void
    {
        Livewire::test(BibleRequestForm::class)
            ->set('name', 'Jane Smith')
            ->set('address', '1 High Street, Crockenhill, BR8 8JS')
            ->set('email', 'not-an-email')
            ->call('submit')
            ->assertHasErrors(['email']);
    }

    #[Test]
    public function name_is_required(): void
    {
        Livewire::test(BibleRequestForm::class)
            ->set('address', '1 High Street')
            ->call('submit')
            ->assertHasErrors(['name'])
            ->assertHasNoErrors(['address']);
    }

    #[Test]
    public function address_is_required(): void
    {
        Livewire::test(BibleRequestForm::class)
            ->set('name', 'Jane Smith')
            ->call('submit')
            ->assertHasErrors(['address'])
            ->assertHasNoErrors(['name']);
    }

    // ── successful submission ─────────────────────────────────────────────

    #[Test]
    public function valid_submission_sends_email_and_sets_submitted_flag(): void
    {
        Mail::fake();

        Livewire::test(BibleRequestForm::class)
            ->set('name', 'Jane Smith')
            ->set('address', '1 High Street, Crockenhill, BR8 8JS')
            ->call('submit')
            ->assertSet('submitted', true)
            ->assertHasNoErrors();

        Mail::assertSent(BibleRequest::class, function (BibleRequest $mail): bool {
            return $mail->data['name'] === 'Jane Smith'
                && $mail->data['address'] === '1 High Street, Crockenhill, BR8 8JS';
        });
    }

    #[Test]
    public function valid_submission_with_optional_fields_sends_email(): void
    {
        Mail::fake();

        Livewire::test(BibleRequestForm::class)
            ->set('name', 'John Doe')
            ->set('address', '2 Church Road, Chelsfield, BR6 7SZ')
            ->set('email', 'john@example.com')
            ->set('phone', '01689 123456')
            ->set('message', 'Please can I have a large-print Bible?')
            ->call('submit')
            ->assertSet('submitted', true);

        Mail::assertSent(BibleRequest::class, function (BibleRequest $mail): bool {
            return $mail->data['email'] === 'john@example.com'
                && $mail->data['phone'] === '01689 123456';
        });
    }

    #[Test]
    public function form_fields_are_cleared_after_successful_submission(): void
    {
        Mail::fake();

        Livewire::test(BibleRequestForm::class)
            ->set('name', 'Jane Smith')
            ->set('address', '1 High Street, Crockenhill, BR8 8JS')
            ->call('submit')
            ->assertSet('name', '')
            ->assertSet('address', '');
    }

    // ── honeypot ──────────────────────────────────────────────────────────

    #[Test]
    public function submission_with_honeypot_filled_silently_succeeds_without_sending_email(): void
    {
        Mail::fake();

        Livewire::test(BibleRequestForm::class)
            ->set('name', 'Bot McBotface')
            ->set('address', '1 Spam Street')
            ->set('website', 'http://spam.example.com')
            ->call('submit')
            ->assertSet('submitted', true);

        Mail::assertNotSent(BibleRequest::class);
    }

    #[Test]
    public function honeypot_field_is_cleared_after_bot_submission(): void
    {
        Mail::fake();

        Livewire::test(BibleRequestForm::class)
            ->set('name', 'Bot')
            ->set('address', '1 Spam Street')
            ->set('website', 'http://spam.example.com')
            ->call('submit')
            ->assertSet('website', '');
    }

    // ── rate limiting ─────────────────────────────────────────────────────

    #[Test]
    public function submit_is_rate_limited_after_three_attempts(): void
    {
        Mail::fake();

        $component = Livewire::test(BibleRequestForm::class);

        for ($i = 0; $i < 3; $i++) {
            $component
                ->set('name', 'Jane Smith')
                ->set('address', '1 High Street')
                ->call('submit');
            // Reset submitted flag between attempts so we can call submit again
            $component->set('submitted', false);
        }

        // Fourth attempt should be rate limited
        $component
            ->set('name', 'Jane Smith')
            ->set('address', '1 High Street')
            ->call('submit')
            ->assertSet('submitted', false)
            ->assertSet('rateLimitError', fn (string $error) => str_contains($error, 'Too many requests'));

        Mail::assertSent(BibleRequest::class, 3);
    }

    #[Test]
    public function rate_limit_does_not_send_email_when_exceeded(): void
    {
        Mail::fake();

        // Exhaust the rate limit
        for ($i = 0; $i < 3; $i++) {
            RateLimiter::hit('bible-request|127.0.0.1', 3600);
        }

        Livewire::test(BibleRequestForm::class)
            ->set('name', 'Jane Smith')
            ->set('address', '1 High Street')
            ->call('submit')
            ->assertSet('submitted', false);

        Mail::assertNotSent(BibleRequest::class);
    }
}
