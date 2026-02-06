<?php

namespace App\Livewire\Admin\Users;

use App\Livewire\Traits\WithNotifications;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class EditUser extends Component
{
    use WithNotifications;

    public User $user;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public bool $isAdmin = false;

    public bool $changePassword = false;

    protected function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$this->user->id,
            'isAdmin' => 'boolean',
        ];

        if ($this->changePassword) {
            $rules['password'] = 'required|string|min:8|same:passwordConfirmation';
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

    public function save(): void
    {
        if ($this->user->id === auth()->id() && ! $this->isAdmin) {
            $this->error('Cannot remove your own admin status');

            return;
        }

        $validated = $this->validate();

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_admin' => $validated['isAdmin'],
        ];

        if ($this->changePassword) {
            $data['password'] = Hash::make($validated['password']);
        }

        $this->user->update($data);

        $this->success('User updated');
        $this->changePassword = false;
        $this->password = '';
        $this->passwordConfirmation = '';
    }

    public function render()
    {
        return view('livewire.admin.users.user-form', [
            'title' => 'Edit User',
            'isEditing' => true,
        ])->layout('layouts.admin', ['title' => 'Edit: '.$this->user->name, 'heading' => 'Edit User']);
    }
}
