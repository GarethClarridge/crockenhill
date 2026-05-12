<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Users;

use App\Livewire\Admin\Users\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EditUserTest extends TestCase
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
        $target = User::factory()->create();

        $this->actingAs($user);

        // Route middleware (auth, verified, admin) enforces access at the HTTP layer.
        // AdminLivewireAuthorizationTest covers this. Direct component mount is unrestricted.
        Livewire::test(EditUser::class, ['user' => $target])
            ->assertOk();
    }

    #[Test]
    public function it_renders_successfully_for_admin(): void
    {
        $target = User::factory()->create(['name' => 'Target User']);

        $this->actingAs($this->admin);

        Livewire::test(EditUser::class, ['user' => $target])
            ->assertStatus(200)
            ->assertSee('Edit User')
            ->assertSet('name', 'Target User')
            ->assertSet('email', $target->email);
    }

    #[Test]
    public function it_can_update_user_details(): void
    {
        $target = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->admin);

        Livewire::test(EditUser::class, ['user' => $target])
            ->set('name', 'New Name')
            ->set('email', 'newemail@example.com')
            ->call('save')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'New Name',
            'email' => 'newemail@example.com',
        ]);
    }

    #[Test]
    public function it_can_change_admin_status_and_logs_it(): void
    {
        $target = User::factory()->create(['is_admin' => false]);

        $this->actingAs($this->admin);

        Log::shouldReceive('warning')
            ->once()
            ->with('User admin status changed via edit form', \Mockery::on(function ($args) use ($target) {
                return $args['target_user_id'] === $target->id &&
                       $args['old_is_admin'] === false &&
                       $args['new_is_admin'] === true;
            }));

        Livewire::test(EditUser::class, ['user' => $target])
            ->set('isAdmin', true)
            ->call('save')
            ->assertDispatched('notify');

        $this->assertTrue($target->fresh()->is_admin);
    }

    #[Test]
    public function it_cannot_remove_own_admin_status(): void
    {
        $this->actingAs($this->admin);

        Log::shouldReceive('warning')->never();

        Livewire::test(EditUser::class, ['user' => $this->admin])
            ->set('isAdmin', false)
            ->call('save')
            ->assertDispatched('notify', function ($name, $params) {
                return $params['type'] === 'error' && $params['message'] === 'Cannot remove your own admin status';
            });

        $this->assertTrue($this->admin->fresh()->is_admin);
    }

    #[Test]
    public function it_can_change_password(): void
    {
        $target = User::factory()->create();
        $oldPassword = $target->password;

        $this->actingAs($this->admin);

        Livewire::test(EditUser::class, ['user' => $target])
            ->set('changePassword', true)
            ->set('password', 'C0mplex_Passw0rd!')
            ->set('passwordConfirmation', 'C0mplex_Passw0rd!')
            ->call('save')
            ->assertDispatched('notify')
            ->assertSet('changePassword', false)
            ->assertSet('password', '')
            ->assertSet('passwordConfirmation', '');

        $this->assertNotEquals($oldPassword, $target->fresh()->password);
        $this->assertTrue(Hash::check('C0mplex_Passw0rd!', $target->fresh()->password));
    }

    #[Test]
    public function it_validates_password_when_changing(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test(EditUser::class, ['user' => $target])
            ->set('changePassword', true)
            ->set('password', 'short')
            ->set('passwordConfirmation', 'short')
            ->call('save')
            ->assertHasErrors(['password']);
    }

    #[Test]
    public function it_validates_email_uniqueness_excluding_current_user(): void
    {
        $target = User::factory()->create(['email' => 'target@example.com']);
        User::factory()->create(['email' => 'other@example.com']);

        $this->actingAs($this->admin);

        Livewire::test(EditUser::class, ['user' => $target])
            ->set('email', 'other@example.com')
            ->call('save')
            ->assertHasErrors(['email' => 'unique'])
            ->set('email', 'target@example.com')
            ->call('save')
            ->assertHasNoErrors(['email']);
    }
}
