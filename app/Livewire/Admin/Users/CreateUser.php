<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Users;

use App\Livewire\Traits\WithNotifications;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Livewire\Component;

class CreateUser extends Component
{
    use WithNotifications;

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
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|same:passwordConfirmation',
            'passwordConfirmation' => 'required',
            'isAdmin' => 'boolean',
            'sendVerification' => 'boolean',
        ];
    }

    public function save(): void
    {
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
