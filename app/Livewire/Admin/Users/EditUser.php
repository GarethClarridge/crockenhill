<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Users;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Component;

class EditUser extends Component
{
    use WithAdminAuthorization, WithNotifications;

    public User $user;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public bool $isAdmin = false;

    public bool $changePassword = false;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$this->user->id,
            'isAdmin' => 'boolean',
        ];

        if ($this->changePassword) {
            $rules['password'] = [
                'required',
                'string',
                'same:passwordConfirmation',
                Password::defaults(),
            ];
            $rules['passwordConfirmation'] = 'required';
        }

        return $rules;
    }

    public function mount(User $user): void
    {

        $this->authorizeAdmin();

        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->isAdmin = $user->is_admin;
    }

    public function save(): void
    {

        $this->authorizeAdmin();

        if ($this->user->id === auth()->id() && ! $this->isAdmin) {
            $this->error('Cannot remove your own admin status');

            return;
        }

        $validated = $this->validate();

        $this->user->name = $validated['name'];
        $this->user->email = $validated['email'];

        if ($this->user->is_admin !== (bool) $validated['isAdmin']) {
            Log::warning('User admin status changed via edit form', [
                'admin_id' => auth()->id(),
                'target_user_id' => $this->user->id,
                'target_user_email' => $this->user->email,
                'old_is_admin' => $this->user->is_admin,
                'new_is_admin' => (bool) $validated['isAdmin'],
            ]);
        }

        // Set sensitive attributes via explicit assignment to bypass mass-assignment protection
        $this->user->is_admin = (bool) $validated['isAdmin'];

        if ($this->changePassword) {
            $this->user->password = Hash::make($validated['password']);
        }

        $this->user->save();

        $this->success('User updated');
        $this->changePassword = false;
        $this->password = '';
        $this->passwordConfirmation = '';
    }

    public function render(): View
    {
        return view('livewire.admin.users.user-form', [
            'title' => 'Edit User',
            'isEditing' => true,
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->user->name, 'heading' => 'Edit User']);
    }
}
