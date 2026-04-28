<x-admin.form-shell :title="$title">
    <x-slot:actions>
        <x-button link="{{ route('admin.users.index') }}" variant="outline" inline>
            Cancel
        </x-button>
        <x-form-button variant="primary" wire:click="save" icon="check" data-form-action="save">
            Save
        </x-form-button>
    </x-slot:actions>

    <x-card heading="User details">
        <div class="space-y-4">
            <x-input label="Name" wire:model="name" required />

            <x-input label="Email" wire:model="email" type="email" required />
        </div>
    </x-card>

    <x-card heading="Password">
        @if($isEditing)
            <x-toggle label="Change Password" wire:model.live="changePassword" />
        @endif

        @if(!$isEditing || $changePassword)
            <div class="space-y-4">
                <x-input label="Password" wire:model="password" type="password" required
                    hint="Minimum 8 characters" />

                <x-input label="Confirm Password" wire:model="passwordConfirmation" type="password" required />
            </div>
        @endif
    </x-card>

    <x-slot:sidebar>
        <x-card heading="Permissions">
            <div class="space-y-4">
                <x-toggle label="Administrator" wire:model="isAdmin"
                    hint="Full access to admin panel" />
            </div>
        </x-card>

        @if(!$isEditing)
            <x-card heading="Email verification">
                <div class="space-y-4">
                    <x-toggle label="Send Verification Email" wire:model="sendVerification"
                        hint="User must verify email before accessing the site" />
                </div>
            </x-card>
        @else
            <x-card heading="Account info">
                <div class="space-y-2">
                    <p class="text-sm"><span class="font-semibold">Created:</span> {{ $user->created_at->format('j M Y') }}</p>
                    @if($user->email_verified_at)
                        <p class="text-sm"><span class="font-semibold">Verified:</span> {{ $user->email_verified_at->format('j M Y') }}</p>
                    @else
                        <p class="text-sm text-amber-600"><span class="font-semibold">Status:</span> Not verified</p>
                    @endif
                </div>
            </x-card>
        @endif
    </x-slot:sidebar>
</x-admin.form-shell>
