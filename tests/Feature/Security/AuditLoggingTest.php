<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\InboundEmailStatus;
use App\Livewire\Admin\ChurchServices\ReviewInboundEmails;
use App\Livewire\Admin\Pages\ListPages;
use App\Livewire\Admin\Preachers\EditPreacher;
use App\Livewire\Admin\Preachers\ListPreachers;
use App\Livewire\Admin\Sermons\ListSermons;
use App\Models\InboundEmail;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\Preacher;
use App\Models\PreacherAlias;
use App\Models\Sermon;
use App\Models\SpeakerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLoggingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function it_logs_sermon_deletion_via_livewire(): void
    {
        Log::spy();
        $sermon = Sermon::factory()->create(['title' => 'Sermon to delete']);

        Livewire::actingAs($this->admin)
            ->test(ListSermons::class)
            ->call('delete', $sermon);

        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);

        Log::assertLogged(\Psr\Log\LogLevel::WARNING, fn ($message, $context) =>
            $message === 'Sermon deleted by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['sermon_id'] === $sermon->id &&
            $context['sermon_title'] === 'Sermon to delete'
        );
    }

    #[Test]
    public function it_logs_page_deletion_via_livewire(): void
    {
        Log::spy();
        $page = Page::factory()->create(['heading' => 'Page to delete']);

        Livewire::actingAs($this->admin)
            ->test(ListPages::class)
            ->call('delete', $page);

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);

        Log::assertLogged(\Psr\Log\LogLevel::WARNING, fn ($message, $context) =>
            $message === 'Page deleted by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['page_id'] === $page->id &&
            $context['page_heading'] === 'Page to delete'
        );
    }

    #[Test]
    public function it_logs_bulk_page_deletion_via_livewire(): void
    {
        Log::spy();
        $pages = Page::factory()->count(3)->create();
        $ids = $pages->pluck('id')->all();

        Livewire::actingAs($this->admin)
            ->test(ListPages::class)
            ->set('selected', $ids)
            ->call('deleteSelected');

        foreach ($ids as $id) {
            $this->assertDatabaseMissing('pages', ['id' => $id]);
        }

        Log::assertLogged(\Psr\Log\LogLevel::WARNING, fn ($message, $context) =>
            $message === 'Multiple pages deleted by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['count'] === 3 &&
            $context['page_ids'] === $ids
        );
    }

    #[Test]
    public function it_logs_preacher_deletion_via_livewire(): void
    {
        Log::spy();
        $preacher = Preacher::factory()->create(['name' => 'Preacher Name']);

        Livewire::actingAs($this->admin)
            ->test(ListPreachers::class)
            ->call('delete', $preacher);

        $this->assertDatabaseMissing('preachers', ['id' => $preacher->id]);

        Log::assertLogged(\Psr\Log\LogLevel::WARNING, fn ($message, $context) =>
            $message === 'Preacher deleted by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['preacher_id'] === $preacher->id &&
            $context['preacher_name'] === 'Preacher Name'
        );
    }

    #[Test]
    public function it_logs_preacher_alias_removal(): void
    {
        Log::spy();
        $preacher = Preacher::factory()->create(['name' => 'Preacher Name']);
        $alias = PreacherAlias::create(['preacher_id' => $preacher->id, 'alias' => 'old alias']);

        Livewire::actingAs($this->admin)
            ->test(EditPreacher::class, ['preacher' => $preacher])
            ->call('removeAlias', $alias->id);

        $this->assertDatabaseMissing('preacher_aliases', ['id' => $alias->id]);

        Log::assertLogged(\Psr\Log\LogLevel::WARNING, fn ($message, $context) =>
            $message === 'Preacher alias removed by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['preacher_id'] === $preacher->id &&
            $context['preacher_name'] === 'Preacher Name' &&
            $context['alias_id'] === $alias->id &&
            $context['alias'] === 'old alias'
        );
    }

    #[Test]
    public function it_logs_speaker_profile_deactivation(): void
    {
        Log::spy();
        $preacher = Preacher::factory()->create(['name' => 'Preacher Name']);
        $profile = SpeakerProfile::factory()->create([
            'preacher_id' => $preacher->id,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin)
            ->test(EditPreacher::class, ['preacher' => $preacher])
            ->call('removeProfile', $profile->id);

        $this->assertDatabaseHas('speaker_profiles', [
            'id' => $profile->id,
            'is_active' => false,
        ]);

        Log::assertLogged(\Psr\Log\LogLevel::WARNING, fn ($message, $context) =>
            $message === 'Speaker profile deactivated by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['preacher_id'] === $preacher->id &&
            $context['preacher_name'] === 'Preacher Name' &&
            $context['speaker_profile_id'] === $profile->id
        );
    }

    #[Test]
    public function it_logs_meeting_deletion_via_controller(): void
    {
        Log::spy();
        $meeting = Meeting::factory()->create(['slug' => 'meeting-slug']);

        $this->actingAs($this->admin)
            ->delete(route('meetings.destroy', $meeting))
            ->assertRedirect();

        $this->assertDatabaseMissing('meetings', ['id' => $meeting->id]);

        Log::assertLogged(\Psr\Log\LogLevel::WARNING, fn ($message, $context) =>
            $message === 'Meeting deleted by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['meeting_id'] === $meeting->id &&
            $context['meeting_slug'] === 'meeting-slug'
        );
    }

    #[Test]
    public function it_logs_sermon_deletion_via_controller(): void
    {
        Log::spy();
        $sermon = Sermon::factory()->create(['title' => 'Sermon Title']);

        // Note: This project uses POST for sermon deletion routes
        $this->actingAs($this->admin)
            ->post(route('sermons.destroy', $sermon->slug))
            ->assertRedirect();

        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);

        Log::assertLogged(\Psr\Log\LogLevel::WARNING, fn ($message, $context) =>
            $message === 'Sermon deleted by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['sermon_id'] === $sermon->id &&
            $context['sermon_title'] === 'Sermon Title'
        );
    }

    #[Test]
    public function it_logs_inbound_email_rejection_via_livewire(): void
    {
        Log::spy();
        $email = InboundEmail::factory()->create([
            'status' => InboundEmailStatus::PENDING->value,
            'subject' => 'Suspicious Email',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ReviewInboundEmails::class)
            ->call('reject', $email->id);

        $email->refresh();
        $this->assertSame(InboundEmailStatus::REJECTED, $email->status);

        Log::assertLogged(\Psr\Log\LogLevel::WARNING, fn ($message, $context) =>
            $message === 'Inbound email rejected by admin' &&
            $context['admin_id'] === $this->admin->id &&
            $context['inbound_email_id'] === $email->id &&
            $context['subject'] === 'Suspicious Email'
        );
    }
}
