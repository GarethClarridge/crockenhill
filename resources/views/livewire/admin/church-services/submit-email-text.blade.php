<div>
    <div class="flex justify-between items-center mb-6">
        <h1 class="font-display text-3xl">Submit Email Text</h1>
        <div class="flex gap-2">
            <x-button link="{{ route('admin.services.inbound-emails') }}" variant="outline" inline>
                Review Emails
            </x-button>
            <x-button link="{{ route('admin.services.upload') }}" variant="outline" icon="arrow-up-tray" inline>
                Upload Service
            </x-button>
        </div>
    </div>

    @if($submitted)
        <div class="max-w-2xl">
            <x-card>
                <div class="space-y-4 text-center py-4">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-green-100">
                        <x-heroicon-o-check-circle class="w-8 h-8 text-green-600" />
                    </div>
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Email submitted for parsing</h2>
                        <p class="mt-1 text-sm text-gray-600">The email has been queued for processing. Check the review list to see the parsed result.</p>
                    </div>
                    <div class="flex justify-center gap-3">
                        <x-button link="{{ route('admin.services.inbound-emails') }}" variant="primary" inline>
                            View in Review List
                        </x-button>
                        <x-form-button variant="outline" wire:click="$set('submitted', false)">
                            Submit Another
                        </x-form-button>
                    </div>
                </div>
            </x-card>
        </div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 space-y-6">
                <x-card heading="Email Body">
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
            </div>

            <div class="space-y-6">
                <x-card heading="Optional Fields">
                    <div class="space-y-4">
                        <p class="text-sm text-gray-600">These fields are optional and only used for display in the review list. Leave blank if not applicable.</p>

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

                <x-card heading="Submit">
                    <div class="space-y-3">
                        <p class="text-sm text-gray-600">The email will be queued via the same pipeline as Mailgun webhook emails. After submission you can review the parse result in the inbound email list.</p>

                        <x-form-button variant="primary" wire:click="submit" wire:loading.attr="disabled" class="w-full justify-center">
                            <span wire:loading.remove wire:target="submit">Submit for parsing</span>
                            <span wire:loading wire:target="submit">Submitting...</span>
                        </x-form-button>
                    </div>
                </x-card>
            </div>
        </div>
    @endif
</div>
