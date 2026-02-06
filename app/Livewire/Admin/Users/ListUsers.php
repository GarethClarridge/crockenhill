<?php

namespace App\Livewire\Admin\Users;

use App\Livewire\Traits\WithNotifications;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class ListUsers extends Component
{
    use WithNotifications, WithPagination;

    public string $search = '';

    public ?bool $verifiedFilter = null;

    public ?bool $adminFilter = null;

    protected $queryString = ['search', 'verifiedFilter', 'adminFilter'];

    public function mount(): void
    {
        // Ensure only admins can access this component
        abort_unless(auth()->user()?->is_admin, 403, 'Unauthorized');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(User $user): void
    {
        // Defense in depth: verify admin status
        abort_unless(auth()->user()?->is_admin, 403, 'Unauthorized');

        if ($user->id === auth()->id()) {
            $this->error('Cannot delete yourself');

            return;
        }

        $user->delete();
        $this->success('User deleted');
    }

    public function toggleAdmin(User $user): void
    {
        // Defense in depth: verify admin status
        abort_unless(auth()->user()?->is_admin, 403, 'Unauthorized');

        if ($user->id === auth()->id()) {
            $this->error('Cannot modify your own admin status');

            return;
        }

        $user->update(['is_admin' => ! $user->is_admin]);
        $this->success($user->is_admin ? 'Admin granted' : 'Admin revoked');
    }

    public function render()
    {
        $users = User::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%"))
            ->when($this->verifiedFilter !== null, fn ($q) => $this->verifiedFilter
                    ? $q->whereNotNull('email_verified_at')
                    : $q->whereNull('email_verified_at'))
            ->when($this->adminFilter !== null, fn ($q) => $q->where('is_admin', $this->adminFilter))
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $headers = [
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'verified', 'label' => 'Verified'],
            ['key' => 'admin', 'label' => 'Role'],
            ['key' => 'created', 'label' => 'Created'],
        ];

        return view('livewire.admin.users.list-users', [
            'users' => $users,
            'headers' => $headers,
        ])->layout('layouts.admin', ['title' => 'Users', 'heading' => 'Users']);
    }
}
