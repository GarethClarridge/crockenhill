<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Users;

use App\Livewire\Admin\Users\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ListUsersTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->crockenhillAdmin()->create(['is_admin' => true]);
    }

    #[Test]
    public function it_relies_on_route_middleware_for_access_control(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user);

        // Route middleware (auth, verified, admin) enforces access at the HTTP layer.
        // AdminLivewireAuthorizationTest covers this. Direct component mount is unrestricted.
        Livewire::test(ListUsers::class)
            ->assertOk();
    }

    #[Test]
    public function it_renders_successfully_for_admin(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ListUsers::class)
            ->assertStatus(200)
            ->assertSee('Users')
            ->assertSee($this->admin->email);
    }

    #[Test]
    public function it_lists_multiple_users(): void
    {
        User::factory()->count(5)->create();

        $this->actingAs($this->admin);

        $test = Livewire::test(ListUsers::class);

        foreach (User::all() as $user) {
            $test->assertSee($user->name);
        }
    }

    #[Test]
    public function it_filters_by_search_term(): void
    {
        $user1 = User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $user2 = User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

        $this->actingAs($this->admin);

        Livewire::test(ListUsers::class)
            ->set('search', 'John')
            ->assertSee($user1->name)
            ->assertDontSee($user2->name)
            ->set('search', 'jane@example.com')
            ->assertSee($user2->name)
            ->assertDontSee($user1->name);
    }

    #[Test]
    public function it_filters_by_verified_status(): void
    {
        $verifiedUser = User::factory()->create(['email_verified_at' => now()]);
        $unverifiedUser = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($this->admin);

        Livewire::test(ListUsers::class)
            ->set('verifiedFilter', true)
            ->assertSee($verifiedUser->email)
            ->assertDontSee($unverifiedUser->email)
            ->set('verifiedFilter', false)
            ->assertSee($unverifiedUser->email)
            ->assertDontSee($verifiedUser->email);
    }

    #[Test]
    public function it_filters_by_admin_status(): void
    {
        $anotherAdmin = User::factory()->admin()->create(['email' => 'another-admin@example.com']);
        $regularUser = User::factory()->create(['email' => 'regular@example.com']);

        $this->actingAs($this->admin);

        Livewire::test(ListUsers::class)
            ->set('adminFilter', true)
            ->assertSee($anotherAdmin->email)
            ->assertDontSee($regularUser->email)
            ->set('adminFilter', false)
            ->assertSee($regularUser->email)
            ->assertDontSee($anotherAdmin->email);
    }

    #[Test]
    public function it_sorts_users(): void
    {
        User::query()->delete(); // Clear for predictable sorting
        $this->admin = User::factory()->crockenhillAdmin()->create(['name' => 'B Admin', 'email' => 'b@example.com']);
        $userA = User::factory()->create(['name' => 'A User', 'email' => 'a@example.com']);
        $userC = User::factory()->create(['name' => 'C User', 'email' => 'c@example.com']);

        $this->actingAs($this->admin);

        Livewire::test(ListUsers::class)
            ->set('sortBy', 'name')
            ->set('sortDirection', 'asc')
            ->assertSeeInOrder(['A User', 'B Admin', 'C User'])
            ->set('sortDirection', 'desc')
            ->assertSeeInOrder(['C User', 'B Admin', 'A User']);
    }

    #[Test]
    public function it_can_delete_a_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin);

        Log::shouldReceive('warning')
            ->once()
            ->with('User deleted by admin', \Mockery::on(function ($args) use ($user) {
                return $args['admin_id'] === $this->admin->id &&
                       $args['deleted_user_id'] === $user->id &&
                       $args['deleted_user_email'] === $user->email;
            }));

        Livewire::test(ListUsers::class)
            ->call('delete', $user->id)
            ->assertDispatched('notify');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    #[Test]
    public function it_cannot_delete_self(): void
    {
        $this->actingAs($this->admin);

        Log::shouldReceive('warning')->never();

        Livewire::test(ListUsers::class)
            ->call('delete', $this->admin->id)
            ->assertDispatched('notify', function ($name, $params) {
                return $params['type'] === 'error' && $params['message'] === 'Cannot delete yourself';
            });

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    #[Test]
    public function it_can_toggle_admin_status(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($this->admin);

        Log::shouldReceive('warning')
            ->once()
            ->with('User admin status toggled', \Mockery::on(function ($args) use ($user) {
                return $args['admin_id'] === $this->admin->id &&
                       $args['target_user_id'] === $user->id &&
                       $args['new_is_admin'] === true;
            }));

        Livewire::test(ListUsers::class)
            ->call('toggleAdmin', $user->id)
            ->assertDispatched('notify');

        $this->assertTrue($user->fresh()->is_admin);

        Log::shouldReceive('warning')
            ->once()
            ->with('User admin status toggled', \Mockery::on(function ($args) {
                return $args['new_is_admin'] === false;
            }));

        Livewire::test(ListUsers::class)
            ->call('toggleAdmin', $user->id)
            ->assertDispatched('notify');

        $this->assertFalse($user->fresh()->is_admin);
    }

    #[Test]
    public function it_cannot_toggle_own_admin_status(): void
    {
        $this->actingAs($this->admin);

        Log::shouldReceive('warning')->never();

        Livewire::test(ListUsers::class)
            ->call('toggleAdmin', $this->admin->id)
            ->assertDispatched('notify', function ($name, $params) {
                return $params['type'] === 'error' && $params['message'] === 'Cannot modify your own admin status';
            });

        $this->assertTrue($this->admin->fresh()->is_admin);
    }
}
