<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Users;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Models\User;
use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Livewire\Component;

class CreateUser extends Component
{
    use SanitizesLogData, WithAdminAuthorization, WithNotifications;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public bool $isAdmin = false;

    public bool $sendVerification = true;

    public function mount(): void
    {

        $this->authorizeAdmin();
    }

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

        $this->authorizeAdmin();

        $validated = $this->validate();

        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // Set sensitive attributes via explicit assignment to bypass mass-assignment protection
        $user->is_admin = $validated['isAdmin'];
        $user->email_verified_at = $this->sendVerification ? null : now();
        $user->save();

        Log::warning('New user created by admin', [
            'admin_id' => auth()->id(),
            'target_user_id' => $user->id,
            'target_user_name' => $this->sanitizeForLog($user->name),
            'target_user_email' => $this->sanitizeForLog($user->email),
            'is_admin' => $user->is_admin,
        ]);

        if ($this->sendVerification) {
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
