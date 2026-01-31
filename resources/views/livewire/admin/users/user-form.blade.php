<div>
    <x-mary-header :title="$title">
        <x-slot:actions>
            <x-mary-button label="Cancel" link="{{ route('admin.users.index') }}" />
            <x-mary-button label="Save" wire:click="save" class="btn-primary" spinner />
        </x-slot:actions>
    </x-mary-header>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Main content --}}
        <div class="lg:col-span-2 space-y-6">
            <x-mary-card title="User Details">
                <div class="space-y-4">
                    <x-mary-input label="Name" wire:model="name" required />

                    <x-mary-input label="Email" wire:model="email" type="email" required />
                </div>
            </x-mary-card>

            <x-mary-card title="Password">
                @if($isEditing)
                    <x-mary-toggle label="Change Password" wire:model.live="changePassword" class="mb-4" />
                @endif

                @if(!$isEditing || $changePassword)
                    <div class="space-y-4">
                        <x-mary-input label="Password" wire:model="password" type="password" required
                            hint="Minimum 8 characters" />

                        <x-mary-input label="Confirm Password" wire:model="passwordConfirmation" type="password" required />
                    </div>
                @endif
            </x-mary-card>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            <x-mary-card title="Permissions">
                <div class="space-y-4">
                    <x-mary-toggle label="Administrator" wire:model="isAdmin"
                        hint="Full access to admin panel" />
                </div>
            </x-mary-card>

            @if(!$isEditing)
                <x-mary-card title="Email Verification">
                    <div class="space-y-4">
                        <x-mary-toggle label="Send Verification Email" wire:model="sendVerification"
                            hint="User must verify email before accessing the site" />
                    </div>
                </x-mary-card>
            @else
                <x-mary-card title="Account Info">
                    <div class="space-y-2">
                        <p class="text-sm"><span class="font-semibold">Created:</span> {{ $user->created_at->format('j M Y') }}</p>
                        @if($user->email_verified_at)
                            <p class="text-sm"><span class="font-semibold">Verified:</span> {{ $user->email_verified_at->format('j M Y') }}</p>
                        @else
                            <p class="text-sm text-warning"><span class="font-semibold">Status:</span> Not verified</p>
                        @endif
                    </div>
                </x-mary-card>
            @endif
        </div>
    </div>
</div>
