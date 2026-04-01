<x-admin.list-shell
    title="Users"
    description="Manage user accounts"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.users.create') }}" variant="primary" icon="plus" inline>
            Create user
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-admin.filter-bar>
            <x-input placeholder="Search users..." wire:model.live.debounce="search"
                icon="magnifying-glass" clearable class="w-64" shortcut="slash" />

            <x-select placeholder="Email Status" wire:model.live="verifiedFilter"
                :options="[['id' => '1', 'name' => 'Verified'], ['id' => '0', 'name' => 'Unverified']]"
                class="w-40" />

            <x-select placeholder="Admin Status" wire:model.live="adminFilter"
                :options="[['id' => '1', 'name' => 'Admin'], ['id' => '0', 'name' => 'Regular']]"
                class="w-40" />

            <x-slot:actions>
                <div x-show="$wire.hasFilters" x-transition x-cloak>
                    <x-form-button variant="ghost" size="sm" icon="x-mark" wire:click="resetFilters">
                        Clear Filters
                    </x-form-button>
                </div>
            </x-slot:actions>
        </x-admin.filter-bar>
    </x-slot:filters>

    <x-slot:pagination>
        {{ $users->links() }}
    </x-slot:pagination>

    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                @foreach($headers as $header)
                    <x-admin.sortable-header
                        :column="$header['key']"
                        :label="$header['label']"
                        :sortable="$header['sortable']"
                        :sortBy="$sortBy"
                        :sortDirection="$sortDirection"
                    />
                @endforeach
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($users as $user)
                <tr class="hover:bg-gray-50">
                    {{-- Name --}}
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $user->name }}</p>
                        <p class="text-sm text-gray-500">{{ $user->email }}</p>
                    </td>
                    {{-- Verified --}}
                    <td class="px-4 py-3">
                        @if($user->email_verified_at)
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-check-circle class="w-5 h-5 text-green-500" aria-hidden="true" />
                                <span class="text-sm">Verified {{ $user->email_verified_at->diffForHumans() }}</span>
                            </div>
                        @else
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-x-circle class="w-5 h-5 text-red-500" aria-hidden="true" />
                                <span class="text-sm">Not verified</span>
                            </div>
                        @endif
                    </td>
                    {{-- Role --}}
                    <td class="px-4 py-3">
                        @if($user->is_admin)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                Admin
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                User
                            </span>
                        @endif
                    </td>
                    {{-- Created --}}
                    <td class="px-4 py-3">
                        <span class="text-sm">{{ $user->created_at->diffForHumans() }}</span>
                    </td>
                    {{-- Actions --}}
                    <td class="px-4 py-3 text-right">
                        <div class="flex gap-1 justify-end" role="group" aria-label="Actions for {{ $user->name }}">
                            @if($user->id !== auth()->id())
                                <x-form-button
                                    variant="ghost"
                                    size="xs"
                                    :icon="$user->is_admin ? 'shield-exclamation' : 'shield-check'"
                                    wire:click="toggleAdmin({{ $user->id }})"
                                    wire:confirm="Toggle admin status for {{ $user->name }}?"
                                    class="{{ $user->is_admin ? 'text-amber-600' : 'text-cbc-teal' }}"
                                    :aria-label="$user->is_admin ? 'Remove admin privileges' : 'Grant admin privileges'" />
                            @endif
                            <x-button link="{{ route('admin.users.edit', $user) }}" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit user: {{ $user->name }}" />
                            @if($user->id !== auth()->id())
                                <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                    wire:click="delete({{ $user->id }})"
                                    wire:confirm="Delete {{ $user->name }}?"
                                    aria-label="Delete user: {{ $user->name }}" />
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <x-admin.empty-state
                    colspan="{{ count($headers) + 1 }}"
                    title="No users found"
                    :hasFilters="$hasFilters"
                >
                    @if(!$hasFilters)
                        <x-button link="{{ route('admin.users.create') }}" variant="primary" icon="plus" inline>
                            Create User
                        </x-button>
                    @endif
                </x-admin.empty-state>
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>
