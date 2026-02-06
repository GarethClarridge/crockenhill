<?php

namespace App\Livewire\Admin\Users;

use App\Livewire\Traits\WithNotifications;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_admin' => $validated['isAdmin'],
            'email_verified_at' => $this->sendVerification ? null : now(),
        ]);

        if ($this->sendVerification) {
            $user->sendEmailVerificationNotification();
        }

        $this->success('User created', redirectTo: route('admin.users.index'));
    }

    public function render()
    {
        return view('livewire.admin.users.user-form', [
            'title' => 'Create User',
            'isEditing' => false,
        ])->layout('layouts.admin', ['title' => 'Create User', 'heading' => 'Create User']);
    }
}
