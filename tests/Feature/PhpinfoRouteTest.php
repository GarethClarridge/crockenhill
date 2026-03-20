<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhpinfoRouteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function phpinfo_route_is_not_accessible_in_non_local_environments(): void
    {
        // In the test environment (not local), the phpinfo route should not be registered at all,
        // or if registered with an isLocal() runtime guard, it must return 404.
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->get('/phpinfo')->assertNotFound();
    }

    #[Test]
    public function phpinfo_route_is_not_registered_outside_local_environment(): void
    {
        // In non-local environments the route is not registered at all, so any
        // request (including guests) must receive 404.
        $this->get('/phpinfo')->assertNotFound();
    }
}
