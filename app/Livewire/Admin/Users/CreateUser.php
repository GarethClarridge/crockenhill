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

class CreateUser extends Component
{
    use WithAdminSave, WithNotifications;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public bool $isAdmin = false;

    public bool $sendVerification = true;

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return array_merge(
            User::validationRules(),
            [
                'password' => [
                    'required',
                    'string',
                    'max:100', // Defense in Depth against DoS
                    'same:passwordConfirmation',
                    Password::defaults(),
                ],
                'passwordConfirmation' => 'required',
                'isAdmin' => 'boolean',
                'sendVerification' => 'boolean',
            ]
        );
    }

    /**
     * Store a newly created user in storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function save(): void
    {
        $validated = $this->validate();

        $sendVerification = $this->sendVerification;
        $user = null;

        $this->adminSave(
            save: function () use ($validated, $sendVerification, &$user): array {
                $user = new User([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                ]);

                // Set sensitive attributes via explicit assignment to bypass mass-assignment protection
                $user->is_admin = $validated['isAdmin'];
                $user->email_verified_at = $sendVerification ? null : now();
                $user->save();

                return [
                    'target_user_id' => $user->id,
                    'target_user_name' => self::sanitizeForLog($user->name),
                    'target_user_email' => self::sanitizeForLog($user->email),
                    'is_admin' => $user->is_admin,
                ];
            },
            logAction: 'New user created by admin',
        );

        if ($sendVerification && $user instanceof User) {
            $user->sendEmailVerificationNotification();
        }

        $this->success('User created', redirectTo: route('admin.users.index'));
    }

    public function render(): View
    {
        return view('livewire.admin.users.user-form', [
            'title' => 'Create User',
            'isEditing' => false,
        ])->layout('layouts.admin', ['title' => 'Create User', 'heading' => 'Create User']);
    }
}
