<x-admin.list-shell
    title="Users"
    description="Manage user accounts"
    :paginator="$users"
    itemsName="user"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.users.create') }}" variant="primary" icon="plus" inline>
            Create user
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-admin.filter-bar loading-target="search, verifiedFilter, adminFilter, resetFilters">
            <x-input placeholder="Search users..." wire:model.live.debounce="search"
                icon="magnifying-glass" clearable class="w-64" shortcut="slash" />

            <x-select placeholder="Email Status" wire:model.live="verifiedFilter"
                :options="[['id' => '1', 'name' => 'Verified'], ['id' => '0', 'name' => 'Unverified']]"
                class="w-40" />

            <x-select placeholder="Admin Status" wire:model.live="adminFilter"
                :options="[['id' => '1', 'name' => 'Admin'], ['id' => '0', 'name' => 'Regular']]"
                class="w-40" />
        </x-admin.filter-bar>
    </x-slot:filters>

    <x-slot:pagination>
        {{ $users->links(data: ['scrollTo' => '#admin-list-results']) }}
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
                <tr wire:loading.class="opacity-50 pointer-events-none" wire:target="delete({{ $user->id }}), toggleAdmin({{ $user->id }})" class="hover:bg-gray-50">
                    {{-- Name --}}
                    <td class="px-4 py-3">
                        <p class="font-medium">{{ $user->name }}</p>
                        <p class="text-sm text-gray-500">{{ $user->email }}</p>
                    </td>
                    {{-- Verified --}}
                    <td class="px-4 py-3">
                        @if($user->email_verified_at)
                            <div class="flex items-center gap-2">
                                <x-badge variant="success" size="xs">Verified</x-badge>
                                <span class="text-xs text-gray-500">{{ $user->email_verified_at->diffForHumans() }}</span>
                            </div>
                        @else
                            <div class="flex items-center gap-2">
                                <x-badge variant="danger" size="xs">Not verified</x-badge>
                            </div>
                        @endif
                    </td>
                    {{-- Role --}}
                    <td class="px-4 py-3">
                        @if($user->is_admin)
                            <x-badge variant="teal" size="xs">Admin</x-badge>
                        @else
                            <x-badge variant="default" size="xs">User</x-badge>
                        @endif
                    </td>
                    {{-- Created --}}
                    <td class="px-4 py-3">
                        <time datetime="{{ $user->created_at->toDateTimeString() }}" class="text-xs text-gray-500">
                            {{ $user->created_at->diffForHumans() }}
                        </time>
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
                                    wire:target="toggleAdmin({{ $user->id }})"
                                    wire:confirm="Toggle admin status for {{ $user->name }}?"
                                    class="{{ $user->is_admin ? 'text-amber-600' : 'text-cbc-teal' }}"
                                    :aria-label="$user->is_admin ? 'Remove admin privileges' : 'Grant admin privileges'" />
                            @endif
                            <x-button link="{{ route('admin.users.edit', $user) }}" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit user: {{ $user->name }}" />
                            @if($user->id !== auth()->id())
                                <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                    wire:click="delete({{ $user->id }})"
                                    wire:target="delete({{ $user->id }})"
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
