<?php

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Actions\InboundEmail\ApproveInboundEmailImport;
use App\Actions\InboundEmail\RejectInboundEmail;
use App\Actions\InboundEmail\ReparseInboundEmail;
use App\Enums\InboundEmailStatus;
use App\Livewire\Admin\ChurchServices\ReviewInboundEmails;
use App\Models\InboundEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReviewInboundEmailsAuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_enforces_admin_authorization_internally_on_mount(): void
    {
        config(['service-tracking.enabled' => true]);
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user);

        Livewire::test(ReviewInboundEmails::class)
            ->assertForbidden();
    }

    #[Test]
    public function it_enforces_admin_authorization_internally_on_approve(): void
    {
        config(['service-tracking.enabled' => true]);
        $user = User::factory()->create(['is_admin' => true]);
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        $inboundEmail = InboundEmail::factory()->create(['status' => InboundEmailStatus::PENDING]);

        $this->actingAs($user);
        $component = Livewire::test(ReviewInboundEmails::class);

        $this->actingAs($nonAdmin);
        $component->call('approve', $inboundEmail->id, Mockery::mock(ApproveInboundEmailImport::class))
            ->assertForbidden();
    }

    #[Test]
    public function it_enforces_admin_authorization_internally_on_edit_and_approve(): void
    {
        config(['service-tracking.enabled' => true]);
        $user = User::factory()->create(['is_admin' => true]);
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        $inboundEmail = InboundEmail::factory()->create(['status' => InboundEmailStatus::PENDING]);

        $this->actingAs($user);
        $component = Livewire::test(ReviewInboundEmails::class);

        $this->actingAs($nonAdmin);
        $component->call('editAndApprove', $inboundEmail->id)
            ->assertForbidden();
    }

    #[Test]
    public function it_enforces_admin_authorization_internally_on_reparse(): void
    {
        config(['service-tracking.enabled' => true]);
        $user = User::factory()->create(['is_admin' => true]);
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        $inboundEmail = InboundEmail::factory()->create(['status' => InboundEmailStatus::PENDING]);

        $this->actingAs($user);
        $component = Livewire::test(ReviewInboundEmails::class);

        $this->actingAs($nonAdmin);
        $component->call('reparse', $inboundEmail->id, Mockery::mock(ReparseInboundEmail::class))
            ->assertForbidden();
    }

    #[Test]
    public function it_enforces_admin_authorization_internally_on_reject(): void
    {
        config(['service-tracking.enabled' => true]);
        $user = User::factory()->create(['is_admin' => true]);
        $nonAdmin = User::factory()->create(['is_admin' => false]);
        $inboundEmail = InboundEmail::factory()->create(['status' => InboundEmailStatus::PENDING]);

        $this->actingAs($user);
        $component = Livewire::test(ReviewInboundEmails::class);

        $this->actingAs($nonAdmin);
        $component->call('reject', $inboundEmail->id, Mockery::mock(RejectInboundEmail::class))
            ->assertForbidden();
    }
}
