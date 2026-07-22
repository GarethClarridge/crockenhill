<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\ChurchServices;

use App\Livewire\Admin\ChurchServices\ManageChurchService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ManageChurchServiceAuthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_relies_on_route_middleware_for_access_control(): void
    {
        config(['service-tracking.enabled' => true]);
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);

        // Route middleware (auth, verified, admin) enforces access at the HTTP layer.
        // AdminLivewireAuthorizationTest covers this. Direct component mount is unrestricted.
        Livewire::test(ManageChurchService::class)
            ->assertOk();
    }
}
