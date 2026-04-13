<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Christ\BibleRequestForm;
use App\Mail\BibleRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BibleRequestFormTest extends TestCase
{
    use DatabaseTransactions;

    // ── page rendering ────────────────────────────────────────────────────

    #[Test]
    public function free_bibles_page_is_publicly_accessible(): void
    {
        $this->get('/christ/free-bibles')
            ->assertOk()
            ->assertSee('Free Bibles');
    }

    #[Test]
    public function free_bibles_page_renders_the_livewire_component(): void
    {
        $this->get('/christ/free-bibles')
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
}
