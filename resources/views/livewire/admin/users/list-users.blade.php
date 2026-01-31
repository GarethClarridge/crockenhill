<div>
    <x-mary-header title="Users" subtitle="Manage user accounts">
        <x-slot:actions>
            <x-mary-button label="Create User" icon="o-plus" link="{{ route('admin.users.create') }}" class="btn-primary" />
        </x-slot:actions>
    </x-mary-header>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <x-mary-input placeholder="Search users..." wire:model.live.debounce="search"
            icon="o-magnifying-glass" clearable class="w-64" />

        <x-mary-select placeholder="Email Status" wire:model.live="verifiedFilter"
            :options="[['id' => '1', 'name' => 'Verified'], ['id' => '0', 'name' => 'Unverified']]"
            class="w-40" />

        <x-mary-select placeholder="Admin Status" wire:model.live="adminFilter"
            :options="[['id' => '1', 'name' => 'Admin'], ['id' => '0', 'name' => 'Regular']]"
            class="w-40" />
    </div>

    {{-- Table --}}
    <x-mary-card>
        <x-mary-table :headers="$headers" :rows="$users" striped>
            @scope('cell_name', $user)
                <div>
                    <p class="font-medium">{{ $user->name }}</p>
                    <p class="text-sm text-base-content/60">{{ $user->email }}</p>
                </div>
            @endscope

            @scope('cell_verified', $user)
                @if($user->email_verified_at)
                    <div class="flex items-center gap-2">
                        <x-mary-icon name="o-check-circle" class="w-5 h-5 text-success" aria-hidden="true" />
                        <span class="text-sm">Verified {{ $user->email_verified_at->diffForHumans() }}</span>
                    </div>
                @else
                    <div class="flex items-center gap-2">
                        <x-mary-icon name="o-x-circle" class="w-5 h-5 text-error" aria-hidden="true" />
                        <span class="text-sm">Not verified</span>
                    </div>
                @endif
            @endscope

            @scope('cell_admin', $user)
                @if($user->is_admin)
                    <x-mary-badge value="Admin" class="badge-primary" />
                @else
                    <x-mary-badge value="User" class="badge-ghost" />
                @endif
            @endscope

            @scope('cell_created', $user)
                <span class="text-sm">{{ $user->created_at->diffForHumans() }}</span>
            @endscope

            @scope('actions', $user)
                <div class="flex gap-1" role="group" aria-label="Actions for {{ $user->name }}">
                    @if($user->id !== auth()->id())
                        <x-mary-button
                            :icon="$user->is_admin ? 'o-shield-exclamation' : 'o-shield-check'"
                            wire:click="toggleAdmin({{ $user->id }})"
                            wire:confirm="Toggle admin status for {{ $user->name }}?"
                            class="btn-ghost btn-xs {{ $user->is_admin ? 'text-warning' : 'text-success' }}"
                            :aria-label="$user->is_admin ? 'Remove admin privileges' : 'Grant admin privileges'" />
                    @endif
                    <x-mary-button icon="o-pencil" link="{{ route('admin.users.edit', $user) }}" class="btn-ghost btn-xs" aria-label="Edit user" />
                    @if($user->id !== auth()->id())
                        <x-mary-button icon="o-trash" wire:click="delete({{ $user->id }})"
                            wire:confirm="Delete {{ $user->name }}?" class="btn-ghost btn-xs text-error" aria-label="Delete user" />
                    @endif
                </div>
            @endscope
        </x-mary-table>

        <div class="mt-4">
            {{ $users->links() }}
        </div>
    </x-mary-card>
</div>
