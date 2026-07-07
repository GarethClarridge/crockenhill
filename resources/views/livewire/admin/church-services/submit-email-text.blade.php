<x-admin.form-shell
    title="Paste Email Text"
    description="Paste an order-of-service email into the same parsing pipeline used by inbound mail."
    save-action="submit"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.inbox', ['filter' => 'emails']) }}" variant="outline" inline>
            Review inbox
        </x-button>
        <x-button link="{{ route('admin.services.upload') }}" variant="outline" icon="arrow-up-tray" inline>
            Upload service
        </x-button>
        @unless($submitted)
            <x-form-button
                type="button"
                variant="primary"
                wire:click="submit"
                icon="paper-airplane"
            >
                Paste email text
            </x-form-button>
        @endunless
    </x-slot:actions>

    @if($submitted)
        <x-card>
            <div class="space-y-4 py-4 text-center">
                <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
                    <x-heroicon-o-check-circle class="h-8 w-8 text-green-600" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Email text pasted for review</h2>
                    <p class="mt-1 text-sm text-gray-600">The email text has been queued for processing. Check the review inbox to see the parsed result.</p>
                </div>
                <div class="flex justify-center gap-3">
                    <x-button link="{{ route('admin.services.inbox', ['filter' => 'emails']) }}" variant="primary" inline>
                        View in review inbox
                    </x-button>
                    <x-form-button type="button" variant="outline" wire:click="$set('submitted', false)">
                        Paste more email text
                    </x-form-button>
                </div>
            </div>
        </x-card>
    @else
        <x-card heading="Email body">
            <div class="space-y-4">
                <p class="text-sm text-gray-600">Paste the plain-text content of an order-of-service email. The parser will extract the service date, slot, and items.</p>

                <x-textarea
                    label="Body"
                    wire:model="bodyPlain"
                    :required="true"
                    :rows="20"
                    hint="Plain text only. Minimum 20 characters."
                    class="font-mono"
                    placeholder="Paste email body here..." />
            </div>
        </x-card>
    @endif

    @unless($submitted)
        <x-slot:sidebar>
            <x-card heading="Optional fields">
                <div class="space-y-4">
                    <p class="text-sm text-gray-600">These fields are optional and only used for display in the review inbox. Leave blank if not applicable.</p>

                    <x-input
                        label="From"
                        wire:model="from"
                        placeholder="sender@example.com" />

                    <x-input
                        label="Subject"
                        wire:model="subject"
                        placeholder="Order of Service – 22 Feb 2026" />
                </div>
            </x-card>

            <x-card heading="Pasting email">
                <div class="space-y-3 text-sm text-gray-600">
                    <p>The email will be queued via the same pipeline as Mailgun webhook emails.</p>
                    <p>After submission you can review the parse result in the inbound email list.</p>
                </div>
            </x-card>
        </x-slot:sidebar>
    @endunless
</x-admin.form-shell>
