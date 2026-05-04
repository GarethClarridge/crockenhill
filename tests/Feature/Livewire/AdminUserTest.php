<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Admin\Users\CreateUser;
use App\Livewire\Admin\Users\EditUser;
use App\Livewire\Admin\Users\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    #[Test]
    public function list_users_component_renders_successfully_for_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertStatus(200)
            ->assertSee('Users');
    }

    #[Test]
    public function list_users_route_forbids_non_admins(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    #[Test]
    public function list_users_route_redirects_guests(): void
    {
        $this->get(route('admin.users.index'))
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function it_can_search_users_by_name_or_email(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
        User::factory()->create(['name' => 'Jane Smith', 'email' => 'jane@example.com']);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->set('search', 'John')
            ->assertSee('John Doe')
            ->assertDontSee('Jane Smith')
            ->set('search', 'jane@example.com')
            ->assertSee('Jane Smith')
            ->assertDontSee('John Doe');
    }

    #[Test]
    public function it_can_filter_users_by_verification_status(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Verified User', 'email_verified_at' => now()]);
        User::factory()->create(['name' => 'Unverified User', 'email_verified_at' => null]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->set('verifiedFilter', true)
            ->assertSee('Verified User')
            ->assertDontSee('Unverified User')
            ->set('verifiedFilter', false)
            ->assertSee('Unverified User')
            ->assertDontSee('Verified User');
    }

    #[Test]
    public function it_can_filter_users_by_admin_status(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin User']);
        User::factory()->create(['name' => 'Regular User', 'is_admin' => false]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->set('adminFilter', true)
            ->assertSee('Admin User')
            ->assertDontSee('Regular User')
            ->set('adminFilter', false)
            ->assertSee('Regular User')
            ->assertDontSee('Admin User');
    }

    #[Test]
    public function it_can_toggle_admin_status(): void
    {
        Log::spy();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->call('toggleAdmin', $user->id)
            ->assertDispatched('notify', type: 'success', message: 'Admin granted');

        $this->assertTrue($user->fresh()->is_admin);

        Log::assertLogged(LogLevel::WARNING, fn (string $message, array $context): bool => $message === 'User admin status toggled'
            && $context['admin_id'] === $admin->id
            && $context['target_user_id'] === $user->id
            && $context['new_is_admin'] === true);

        Livewire::test(ListUsers::class)
            ->call('toggleAdmin', $user->id)
            ->assertDispatched('notify', type: 'success', message: 'Admin revoked');

        $this->assertFalse($user->fresh()->is_admin);

        Log::assertLogged(LogLevel::WARNING, fn (string $message, array $context): bool => $message === 'User admin status toggled'
            && $context['new_is_admin'] === false);
    }

    #[Test]
    public function it_cannot_toggle_own_admin_status(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->call('toggleAdmin', $admin->id)
            ->assertDispatched('notify', type: 'error', message: 'Cannot modify your own admin status');

        $this->assertTrue($admin->fresh()->is_admin);
    }

    #[Test]
    public function it_can_delete_user(): void
    {
        Log::spy();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->call('delete', $user->id)
            ->assertDispatched('notify', type: 'success', message: 'User deleted');

        $this->assertModelMissing($user);

        Log::assertLogged(LogLevel::WARNING, fn (string $message, array $context): bool => $message === 'User deleted by admin'
            && $context['admin_id'] === $admin->id
            && $context['deleted_user_id'] === $user->id);
    }

    #[Test]
    public function it_cannot_delete_self(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->call('delete', $admin->id)
            ->assertDispatched('notify', type: 'error', message: 'Cannot delete yourself');

        $this->assertModelExists($admin);
    }

    #[Test]
    public function it_can_sort_users_by_name(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin User']);
        User::factory()->create(['name' => 'Alice']);
        User::factory()->create(['name' => 'Zebra']);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->set('sortBy', 'name')
            ->set('sortDirection', 'asc')
            ->assertSeeInOrder(['Admin User', 'Alice', 'Zebra'])
            ->set('sortDirection', 'desc')
            ->assertSeeInOrder(['Zebra', 'Alice', 'Admin User']);
    }

    #[Test]
    public function it_can_sort_users_by_created_at(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Old User', 'created_at' => now()->subDays(10)]);
        User::factory()->create(['name' => 'New User', 'created_at' => now()]);

        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->set('sortBy', 'created_at')
            ->set('sortDirection', 'asc')
            ->assertSeeInOrder(['Old User', 'New User'])
            ->set('sortDirection', 'desc')
            ->assertSeeInOrder(['New User', 'Old User']);
    }

    #[Test]
    public function it_resets_invalid_sort_columns_to_default(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->set('sortBy', 'password')
            ->assertSet('sortBy', 'created_at');
    }

    #[Test]
    public function it_can_toggle_sort_direction_via_sort_action(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->call('sort', 'name')
            ->assertSet('sortBy', 'name')
            ->assertSet('sortDirection', 'asc')
            ->call('sort', 'name')
            ->assertSet('sortBy', 'name')
            ->assertSet('sortDirection', 'desc')
            ->call('sort', 'email')
            ->assertSet('sortBy', 'email')
            ->assertSet('sortDirection', 'asc');
    }

    #[Test]
    public function create_user_validation_rules(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->set('name', '')
            ->set('email', '')
            ->set('password', '')
            ->call('save')
            ->assertHasErrors(['name', 'email', 'password'])
            ->set('email', 'not-an-email')
            ->set('password', 'short')
            ->call('save')
            ->assertHasErrors(['email', 'password'])
            ->set('password', 'password123') // 11 chars, no symbols
            ->set('passwordConfirmation', 'password123')
            ->call('save')
            ->assertHasErrors(['password'])
            ->set('password', 'Short1!') // 7 chars, has everything but length
            ->set('passwordConfirmation', 'Short1!')
            ->call('save')
            ->assertHasErrors(['password'])
            ->set('password', 'PasswordNoSymbols123') // 20 chars, no symbols
            ->set('passwordConfirmation', 'PasswordNoSymbols123')
            ->call('save')
            ->assertHasErrors(['password'])
            ->set('password', 'StrongPassword123!')
            ->set('passwordConfirmation', 'different')
            ->call('save')
            ->assertHasErrors(['password']);
    }

    #[Test]
    public function it_can_create_a_standard_user(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->set('name', 'New User')
            ->set('email', 'new@example.com')
            ->set('password', 'ValidP@ssword123')
            ->set('passwordConfirmation', 'ValidP@ssword123')
            ->set('isAdmin', false)
            ->set('sendVerification', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'is_admin' => false,
            'email_verified_at' => null,
        ]);
    }

    #[Test]
    public function it_can_create_an_admin_user_without_verification(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateUser::class)
            ->set('name', 'New Admin')
            ->set('email', 'admin_new@example.com')
            ->set('password', 'ValidP@ssword123')
            ->set('passwordConfirmation', 'ValidP@ssword123')
            ->set('isAdmin', true)
            ->set('sendVerification', false)
            ->call('save')
            ->assertRedirect(route('admin.users.index'));

        $user = User::where('email', 'admin_new@example.com')->first();
        $this->assertTrue($user->is_admin);
        $this->assertNotNull($user->email_verified_at);
    }

    #[Test]
    public function edit_user_component_renders_with_user_data(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Existing User', 'email' => 'existing@example.com']);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['user' => $user])
            ->assertSet('name', 'Existing User')
            ->assertSet('email', 'existing@example.com')
            ->assertSet('isAdmin', false);
    }

    #[Test]
    public function it_can_update_user_name_and_email(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['user' => $user])
            ->set('name', 'New Name')
            ->set('email', 'new_email@example.com')
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'User updated');

        $this->assertEquals('New Name', $user->fresh()->name);
        $this->assertEquals('new_email@example.com', $user->fresh()->email);
    }

    #[Test]
    public function it_can_update_user_password(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $oldPassword = $user->password;

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['user' => $user])
            ->set('changePassword', true)
            ->set('password', 'ValidP@ssword123')
            ->set('passwordConfirmation', 'ValidP@ssword123')
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'User updated');

        $this->assertNotEquals($oldPassword, $user->fresh()->password);
        $this->assertTrue(\Hash::check('ValidP@ssword123', $user->fresh()->password));

        // Test weak password rejection in edit mode
        Livewire::test(EditUser::class, ['user' => $user])
            ->set('changePassword', true)
            ->set('password', 'weak')
            ->set('passwordConfirmation', 'weak')
            ->call('save')
            ->assertHasErrors(['password']);
    }

    #[Test]
    public function it_can_toggle_admin_status_in_edit_mode(): void
    {
        Log::spy();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['user' => $user])
            ->set('isAdmin', true)
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'User updated');

        $this->assertTrue($user->fresh()->is_admin);

        Log::assertLogged(LogLevel::WARNING, fn (string $message, array $context): bool => $message === 'User admin status changed via edit form'
            && $context['admin_id'] === $admin->id
            && $context['target_user_id'] === $user->id
            && $context['old_is_admin'] === false
            && $context['new_is_admin'] === true);

        Livewire::test(EditUser::class, ['user' => $user])
            ->set('isAdmin', false)
            ->call('save')
            ->assertDispatched('notify', type: 'success', message: 'User updated');

        $this->assertFalse($user->fresh()->is_admin);

        Log::assertLogged(LogLevel::WARNING, fn (string $message, array $context): bool => $message === 'User admin status changed via edit form'
            && $context['new_is_admin'] === false);
    }

    #[Test]
    public function it_cannot_remove_own_admin_status_in_edit_mode(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['user' => $admin])
            ->set('isAdmin', false)
            ->call('save')
            ->assertDispatched('notify', type: 'error', message: 'Cannot remove your own admin status');

        $this->assertTrue($admin->fresh()->is_admin);
    }
}
