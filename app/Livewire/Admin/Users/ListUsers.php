<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Users;

use App\Livewire\Traits\WithAdminAuthorization;
use App\Livewire\Traits\WithAdminDelete;
use App\Livewire\Traits\WithAdminSave;
use App\Livewire\Traits\WithFilterableListing;
use App\Livewire\Traits\WithNotifications;
use App\Livewire\Traits\WithSortableListing;
use App\Models\User;
use App\Traits\EscapesLikeWildcards;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ListUsers extends Component
{
    use EscapesLikeWildcards, WithAdminAuthorization, WithAdminDelete, WithAdminSave, WithFilterableListing, WithNotifications, WithPagination, WithSortableListing;

    protected const DEFAULT_SORT_COLUMN = 'created_at';

    protected const DEFAULT_SORT_DIRECTION = 'desc';

    protected const ALLOWED_SORT_COLUMNS = [
        'name',
        'email',
        'is_admin',
        'created_at',
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?bool $verifiedFilter = null;

    #[Url(except: null)]
    public ?bool $adminFilter = null;

    #[Url(except: self::DEFAULT_SORT_COLUMN)]
    public string $sortBy = self::DEFAULT_SORT_COLUMN;

    #[Url(except: self::DEFAULT_SORT_DIRECTION)]
    public string $sortDirection = self::DEFAULT_SORT_DIRECTION;

    /**
     * @return array<string, mixed>
     */
    protected function filterProperties(): array
    {
        return [
            'search' => '',
            'verifiedFilter' => null,
            'adminFilter' => null,
            'sortBy' => self::DEFAULT_SORT_COLUMN,
            'sortDirection' => self::DEFAULT_SORT_DIRECTION,
        ];
    }

    /**
     * Remove the specified user from storage.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function delete(User $user): void
    {
        if ($user->id === auth()->id()) {
            $this->error('Cannot delete yourself');

            return;
        }

        $this->adminDelete(
            model: $user,
            logAction: 'User deleted by admin',
            logFields: [
                'deleted_user_id' => $user->id,
                'deleted_user_email' => $this->sanitizeForLog((string) $user->email),
            ],
        );

        $this->success('User deleted');
    }

    /**
     * Toggle administrative privileges for a user.
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     */
    public function toggleAdmin(User $user): void
    {
        if ($user->id === auth()->id()) {
            $this->error('Cannot modify your own admin status');

            return;
        }

        $this->adminSave(
            save: function () use ($user): array {
                // Set sensitive attributes via explicit assignment to bypass mass-assignment protection
                $user->is_admin = ! $user->is_admin;
                $user->save();

                return [
                    'target_user_id' => $user->id,
                    'target_user_email' => $this->sanitizeForLog((string) $user->email),
                    'new_is_admin' => $user->is_admin,
                ];
            },
            logAction: 'User admin status toggled',
        );

        $this->success($user->is_admin ? 'Admin granted' : 'Admin revoked');
    }

    public function render(): View
    {
        $this->sanitizeSorting();
        $this->computeHasFilters();

        $escapedSearch = $this->escapeLike(trim($this->search));

        /**
         * Performance Optimization: Limits retrieved columns for users to required fields
         * to reduce memory usage and DB I/O. Search terms are escaped to prevent LIKE injection.
         * Search conditions are grouped to ensure correct query logic with other filters.
         */
        $users = User::query()
            ->select(['id', 'name', 'email', 'is_admin', 'email_verified_at', 'created_at'])
            ->when($this->search !== '', fn ($q) => $q->where(fn ($sub) => $sub->where('name', 'like', "%{$escapedSearch}%")
                ->orWhere('email', 'like', "%{$escapedSearch}%")))
            ->when($this->verifiedFilter !== null, fn ($q) => $this->verifiedFilter
                    ? $q->whereNotNull('email_verified_at')
                    : $q->whereNull('email_verified_at'))
            ->when($this->adminFilter !== null, fn ($q) => $q->where('is_admin', $this->adminFilter))
            ->orderBy($this->sortBy, $this->sortDirection === 'desc' ? 'desc' : 'asc')
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
