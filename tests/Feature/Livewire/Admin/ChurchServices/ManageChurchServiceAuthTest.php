<?php

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Livewire\Admin\ChurchServices\ManageChurchService;
use App\Models\ChurchService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManageChurchServiceAuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_enforces_admin_authorization_internally(): void
    {
        config(['service-tracking.enabled' => true]);
        $user = User::factory()->create(['is_admin' => false]);
        $service = ChurchService::factory()->create();

        $this->actingAs($user);

        // mount() should fail
        Livewire::test(ManageChurchService::class, ['churchService' => $service])
            ->assertForbidden();
    }
}
