<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin\Users;

use App\Livewire\Admin\Users\CreateUser;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateUserTest extends TestCase
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
        Livewire::test(CreateUser::class)
            ->assertOk();
    }

    #[Test]
    public function it_renders_successfully_for_admin(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateUser::class)
            ->assertStatus(200)
            ->assertSee('Create User');
    }

    #[Test]
    public function it_can_create_a_user(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateUser::class)
            ->set('name', 'New User')
            ->set('email', 'newuser@example.com')
            ->set('password', 'C0mplex_Passw0rd!')
            ->set('passwordConfirmation', 'C0mplex_Passw0rd!')
            ->set('isAdmin', true)
            ->set('sendVerification', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'is_admin' => true,
        ]);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertTrue(Hash::check('C0mplex_Passw0rd!', $user->password));
        $this->assertNotNull($user->email_verified_at);
    }

    #[Test]
    public function it_sends_verification_email_when_requested(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        Livewire::test(CreateUser::class)
            ->set('name', 'Verify Me')
            ->set('email', 'verify@example.com')
            ->set('password', 'C0mplex_Passw0rd!')
            ->set('passwordConfirmation', 'C0mplex_Passw0rd!')
            ->set('sendVerification', true)
            ->call('save');

        $user = User::where('email', 'verify@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateUser::class)
            ->set('name', '')
            ->set('email', '')
            ->set('password', '')
            ->call('save')
            ->assertHasErrors(['name', 'email', 'password']);
    }

    #[Test]
    public function it_validates_email_uniqueness(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);
        $this->actingAs($this->admin);

        Livewire::test(CreateUser::class)
            ->set('email', 'existing@example.com')
            ->call('save')
            ->assertHasErrors(['email' => 'unique']);
    }

    #[Test]
    public function it_validates_password_confirmation(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateUser::class)
            ->set('password', 'C0mplex_Passw0rd!')
            ->set('passwordConfirmation', 'Different!')
            ->call('save')
            ->assertHasErrors(['password' => 'same']);
    }

    #[Test]
    public function it_enforces_password_complexity(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateUser::class)
            ->set('password', 'short')
            ->set('passwordConfirmation', 'short')
            ->call('save')
            ->assertHasErrors(['password']);
    }
}
