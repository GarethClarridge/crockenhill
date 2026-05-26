<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Users;

use App\Livewire\Traits\WithAdminSave;
use App\Livewire\Traits\WithNotifications;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Component;

class EditUser extends Component
{
    use WithAdminSave, WithNotifications;

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
        $rules = array_merge(
            User::validationRules($this->user),
            [
                'isAdmin' => 'boolean',
            ]
        );

        if ($this->changePassword) {
            $rules['password'] = [
                'required',
                'string',
                'max:100', // Defense in Depth against DoS
                'same:passwordConfirmation',
                Password::defaults(),
            ];
            $rules['passwordConfirmation'] = 'required';
        }

        return $rules;
    }

    public function mount(User $user): void
    {
        $this->user = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->isAdmin = $user->is_admin;
    }

    /**
     * Update the specified user in storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function save(): void
    {
        $this->authorizeAdmin();

        if ($this->user->id === auth()->id() && ! $this->isAdmin) {
            $this->error('Cannot remove your own admin status');

            return;
        }

        $validated = $this->validate();
        $changePassword = $this->changePassword;

        $this->adminSave(
            save: function () use ($validated, $changePassword): array {
                $this->user->name = $validated['name'];
                $this->user->email = $validated['email'];

                $oldIsAdmin = $this->user->is_admin;
                $newIsAdmin = (bool) $validated['isAdmin'];

                // Set sensitive attributes via explicit assignment to bypass mass-assignment protection
                $this->user->is_admin = $newIsAdmin;

                if ($changePassword) {
                    $this->user->password = Hash::make($validated['password']);
                }

                $this->user->save();

                if ($oldIsAdmin === $newIsAdmin) {
                    return ['target_user_id' => $this->user->id];
                }

                return [
                    'target_user_id' => $this->user->id,
                    'target_user_email' => $this->sanitizeForLog($this->user->email),
                    'old_is_admin' => $oldIsAdmin,
                    'new_is_admin' => $newIsAdmin,
                ];
            },
            logAction: 'User admin status changed via edit form',
        );

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
