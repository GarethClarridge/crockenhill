<x-admin.form-shell
    title="Add to service"
    description="Add an order of service or recording without choosing a parser first"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
            Back to services
        </x-button>

        @if($intent === 'plan' && ! $submitted)
            <x-form-button type="button" variant="primary" wire:click="importPlan" icon="arrow-up-tray">
                Import plan
            </x-form-button>
        @endif
    </x-slot:actions>

    <nav class="flex flex-wrap gap-2" aria-label="Choose what to add">
        <x-button
            :link="route('admin.services.add', array_filter(['intent' => 'plan', 'churchServiceId' => $churchServiceId]))"
            :variant="$intent === 'plan' ? 'primary' : 'outline'"
            inline
            :aria-current="$intent === 'plan' ? 'page' : null"
        >
            Order of service
        </x-button>
        <x-button
            :link="route('admin.services.add', array_filter(['intent' => 'recording', 'churchServiceId' => $churchServiceId]))"
            :variant="$intent === 'recording' ? 'primary' : 'outline'"
            inline
            :aria-current="$intent === 'recording' ? 'page' : null"
        >
            Recording
        </x-button>
    </nav>

    @if($intent === 'recording')
        <x-card heading="Upload a recording">
            <div class="space-y-4">
                <p class="text-sm text-gray-600">
                    Add sermon audio, a sermon video, or a full livestream using the recording uploader.
                </p>
                <x-button link="{{ route('admin.services.upload-recording', array_filter(['churchServiceId' => $churchServiceId])) }}" variant="primary" icon="film" inline>
                    Upload a recording
                </x-button>
            </div>
        </x-card>
    @elseif($submitted)
        <x-card>
            <div class="space-y-4 py-4 text-center">
                <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
                    <x-heroicon-o-check-circle class="h-8 w-8 text-green-600" aria-hidden="true" />
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Email text imported for review</h2>
                    <p class="mt-1 text-sm text-gray-600">The email text has been queued for processing. Check Needs attention on the services page to see the parsed result.</p>
                </div>
                <div class="flex flex-wrap justify-center gap-3">
                    <x-button link="{{ route('admin.services.index') }}" variant="primary" inline>
                        View services
                    </x-button>
                    <x-form-button type="button" variant="outline" wire:click="resetEmailForm">
                        Add another order of service
                    </x-form-button>
                </div>
            </div>
        </x-card>
    @else
        <x-card heading="Upload an OpenLP file or paste an email">
            <div class="space-y-6">
                <div>
                    <label for="service-upload" class="mb-1 block text-sm font-medium text-gray-700">
                        OpenLP file
                    </label>
                    <input
                        id="service-upload"
                        type="file"
                        wire:model="file"
                        accept=".osz,.zip,application/zip"
                        aria-describedby="file-help @error('file') file-error @enderror"
                        @error('file') aria-invalid="true" @enderror
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 sm:text-sm"
                    />
                    <p id="file-help" class="mt-1 text-sm text-gray-500">
                        OpenLP `.osz` archive, up to {{ round(((int) config('service-tracking.upload.max_size_kb', 614400)) / 1024, 1) }} MB. The date and service are inferred from the filename.
                    </p>
                    @error('file')
                        <p id="file-error" class="mt-1 text-sm text-red-600" role="alert">{{ $message }}</p>
                    @enderror

                    @if($file)
                        <div class="mt-3 rounded-md border border-green-200 bg-green-50 px-4 py-3">
                            <p class="text-sm text-green-800">Selected: <span class="font-medium">{{ $file->getClientOriginalName() }}</span></p>
                        </div>
                    @endif
                </div>

                <div class="flex items-center gap-3" aria-hidden="true">
                    <div class="h-px grow bg-gray-200"></div>
                    <span class="text-xs font-medium uppercase tracking-wider text-gray-500">or</span>
                    <div class="h-px grow bg-gray-200"></div>
                </div>

                <x-textarea
                    label="Email body"
                    wire:model="bodyPlain"
                    :rows="16"
                    hint="Paste the plain-text order-of-service email. Minimum 20 characters."
                    class="font-mono"
                    placeholder="Paste email body here..."
                />

                <details class="rounded-md border border-gray-200 bg-gray-50 p-4">
                    <summary class="cursor-pointer text-sm font-medium text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2">
                        Optional email details
                    </summary>
                    <div class="mt-4 space-y-4">
                        <x-input label="From" wire:model="from" placeholder="sender@example.com" />
                        <x-input label="Subject" wire:model="subject" placeholder="Order of Service – 22 Feb 2026" />
                    </div>
                </details>

                @error('planInput')
                    <p class="text-sm text-red-600" role="alert">{{ $message }}</p>
                @enderror

                <p class="text-sm text-gray-500" wire:loading wire:target="file,importPlan" role="status">
                    Importing order of service...
                </p>

                <div class="border-t border-gray-200 pt-4">
                    <x-button link="{{ route('admin.services.create') }}" variant="ghost" icon="pencil-square" inline>
                        Start from a blank plan
                    </x-button>
                </div>
            </div>
        </x-card>
    @endif

    @if($intent === 'plan' && ! $submitted)
        <x-slot:sidebar>
            <x-card heading="How imports work">
                <div class="space-y-3 text-sm text-gray-600">
                    <p>OpenLP files are imported immediately. Low-confidence imports are flagged for review.</p>
                    <p>Email text uses the same parsing pipeline as inbound mail and appears under Needs attention when ready.</p>
                </div>
            </x-card>

            <x-card heading="Recent imports">
                <div class="space-y-3">
                    @forelse($recentServices as $service)
                        <a href="{{ route('admin.services.show', $service) }}" wire:navigate class="block rounded-md border border-gray-200 px-3 py-2 no-underline hover:bg-gray-50">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-medium text-gray-900">{{ $service->date->format('j M Y') }} ({{ $service->service->label() }})</p>
                                <span class="text-xs text-gray-500">{{ $service->items_count }} {{ \Illuminate\Support\Str::plural('item', $service->items_count) }}</span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ $service->original_filename }}</p>
                        </a>
                    @empty
                        <p class="text-sm text-gray-500">No imports yet.</p>
                    @endforelse
                </div>
            </x-card>
        </x-slot:sidebar>
    @endif
</x-admin.form-shell>
