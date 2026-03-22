<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Users;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithSortableListing;
use App\Models\User;
use App\Traits\EscapesLikeWildcards;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ListUsers extends Component
{
    use EscapesLikeWildcards, WithAdminAuthorization, WithNotifications, WithPagination, WithSortableListing;

    protected const DEFAULT_SORT_COLUMN = 'created_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    protected const ALLOWED_SORT_COLUMNS = [
        'name',
        'email',
        'is_admin',
        'created_at',
    ];

    public string $search = '';

    public ?bool $verifiedFilter = null;

    public ?bool $adminFilter = null;

    public bool $hasFilters = false;

    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    /** @var array<int, string> */
    protected array $queryString = ['search', 'verifiedFilter', 'adminFilter', 'sortBy', 'sortDirection'];

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'verifiedFilter', 'adminFilter', 'sortBy', 'sortDirection']);
        $this->resetPage();
    }

    public function delete(User $user): void
    {
        $this->authorizeAdmin();

        if ($user->id === auth()->id()) {
            $this->error('Cannot delete yourself');

            return;
        }

        $user->delete();
        $this->success('User deleted');
    }

    public function toggleAdmin(User $user): void
    {
        $this->authorizeAdmin();

        if ($user->id === auth()->id()) {
            $this->error('Cannot modify your own admin status');

            return;
        }

        // Set sensitive attributes via explicit assignment to bypass mass-assignment protection
        $user->is_admin = ! $user->is_admin;
        $user->save();

        $this->success($user->is_admin ? 'Admin granted' : 'Admin revoked');
    }

    public function render(): View
    {
        $this->sanitizeSorting();

        $this->hasFilters = ! empty($this->search)
            || $this->verifiedFilter !== null
            || $this->adminFilter !== null
            || $this->sortBy !== self::DEFAULT_SORT_COLUMN
            || $this->sortDirection !== self::DEFAULT_SORT_DIRECTION;

        $escapedSearch = $this->escapeLike($this->search);

        /**
         * Performance Optimization: Limits retrieved columns for users to required fields
         * to reduce memory usage and DB I/O. Search terms are escaped to prevent LIKE injection.
         */
        $users = User::query()
            ->select(['id', 'name', 'email', 'is_admin', 'email_verified_at', 'created_at'])
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$escapedSearch}%")
                ->orWhere('email', 'like', "%{$escapedSearch}%"))
            ->when($this->verifiedFilter !== null, fn ($q) => $this->verifiedFilter
                    ? $q->whereNotNull('email_verified_at')
                    : $q->whereNull('email_verified_at'))
            ->when($this->adminFilter !== null, fn ($q) => $q->where('is_admin', $this->adminFilter))
            ->orderBy($this->sortBy, $this->sortDirection)
            ->paginate(20);

        $headers = [
            ['key' => 'name', 'label' => 'Name', 'sortable' => true],
            ['key' => 'verified', 'label' => 'Verified', 'sortable' => false],
            ['key' => 'is_admin', 'label' => 'Role', 'sortable' => true],
            ['key' => 'created_at', 'label' => 'Created', 'sortable' => true],
        ];

        return view('livewire.admin.users.list-users', [
            'users' => $users,
            'headers' => $headers,
        ])->layout('layouts.admin', ['title' => 'Users', 'heading' => 'Users']);
    }
}
