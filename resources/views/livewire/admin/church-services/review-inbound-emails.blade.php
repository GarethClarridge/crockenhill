<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="font-display text-3xl">Inbound Email Review</h1>
            <p class="text-gray-600">Review low-confidence or failed order-of-service emails before they become canonical.</p>
        </div>

        <div class="flex gap-2">
            <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
                Back to Services
            </x-button>
            <x-button link="{{ route('admin.services.upload') }}" variant="outline" icon="arrow-up-tray" inline>
                Upload Service
            </x-button>
        </div>
    </div>

    <div class="flex flex-wrap gap-4">
        <x-input
            placeholder="Search sender, subject, or message ID..."
            wire:model.live.debounce="search"
            icon="magnifying-glass"
            clearable
            class="w-80" />

        <x-select
            placeholder="Status"
            wire:model.live="statusFilter"
            :options="$statusOptions"
            class="w-48" />
    </div>

    <x-card>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Received</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Email</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Parsed Preview</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white">
                    @forelse($inboundEmails as $inboundEmail)
                        @php
                            $metadata = is_array($inboundEmail->processing_metadata) ? $inboundEmail->processing_metadata : [];
                            $parsing = is_array($metadata['parsing'] ?? null) ? $metadata['parsing'] : [];
                            $previewItems = is_array($parsing['items'] ?? null) ? $parsing['items'] : [];
                            $warnings = is_array($parsing['warnings'] ?? null) ? $parsing['warnings'] : [];
                            $failure = is_array($metadata['failure'] ?? null) ? $metadata['failure'] : [];
                            $resolvedDate = $parsing['resolved_date'] ?? null;
                            $resolvedService = $parsing['resolved_service'] ?? null;
                            $confidenceScore = is_numeric($parsing['confidence_score'] ?? null) ? (float) $parsing['confidence_score'] : null;
                        @endphp
                        <tr wire:key="inbound-email-{{ $inboundEmail->id }}" class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm">
                                <p class="font-medium">{{ $inboundEmail->received_at->format('j M Y') }}</p>
                                <p class="text-xs text-gray-500">{{ $inboundEmail->received_at->format('H:i') }}</p>
                                <span class="mt-2 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $inboundEmail->status === \App\Enums\InboundEmailStatus::FAILED ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800' }}">
                                    {{ $inboundEmail->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <p class="font-medium">{{ $inboundEmail->subject }}</p>
                                <p class="mt-1 text-xs text-gray-500">{{ $inboundEmail->from }}</p>
                                <p class="mt-1 text-xs text-gray-400">{{ $inboundEmail->message_id }}</p>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <div class="space-y-2">
                                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                                        <span><span class="font-medium text-gray-700">Date:</span> {{ $resolvedDate ?: 'Unknown' }}</span>
                                        <span><span class="font-medium text-gray-700">Service:</span> {{ $resolvedService ? str($resolvedService)->title() : 'Unknown' }}</span>
                                        <span><span class="font-medium text-gray-700">Confidence:</span> {{ $confidenceScore !== null ? number_format($confidenceScore * 100, 0).'%' : 'Unknown' }}</span>
                                    </div>

                                    @if($previewItems !== [])
                                        <div class="space-y-1">
                                            @foreach(array_slice($previewItems, 0, 4) as $previewItem)
                                                @php
                                                    $previewMetadata = is_array($previewItem['metadata'] ?? null) ? $previewItem['metadata'] : [];
                                                    $previewType = $previewMetadata['section_type'] ?? $previewMetadata['email_type'] ?? $previewItem['type'] ?? 'item';
                                                @endphp
                                                <p class="text-sm text-gray-700">
                                                    <span class="font-medium text-gray-900">{{ \Illuminate\Support\Str::title(str_replace('_', ' ', (string) $previewType)) }}</span>
                                                    <span class="text-gray-500">·</span>
                                                    {{ $previewItem['title'] ?? 'Untitled item' }}
                                                </p>
                                            @endforeach

                                            @if(count($previewItems) > 4)
                                                <p class="text-xs text-gray-500">+{{ count($previewItems) - 4 }} more item(s)</p>
                                            @endif
                                        </div>
                                    @else
                                        <p class="rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-gray-500">
                                            No parsed preview is available yet.
                                        </p>
                                    @endif

                                    @if($warnings !== [])
                                        <p class="text-xs text-amber-700">{{ $warnings[0] }}</p>
                                    @endif

                                    @if(isset($failure['message']) && is_string($failure['message']))
                                        <p class="text-xs text-rose-700">{{ $failure['message'] }}</p>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    <x-form-button
                                        type="button"
                                        size="xs"
                                        variant="primary"
                                        wire:click="approve({{ $inboundEmail->id }})"
                                        wire:target="approve({{ $inboundEmail->id }})"
                                    >
                                        Approve
                                    </x-form-button>

                                    <x-form-button
                                        type="button"
                                        size="xs"
                                        variant="outline"
                                        wire:click="editAndApprove({{ $inboundEmail->id }})"
                                        wire:target="editAndApprove({{ $inboundEmail->id }})"
                                    >
                                        Edit &amp; Approve
                                    </x-form-button>

                                    <x-form-button
                                        type="button"
                                        size="xs"
                                        variant="outline"
                                        wire:click="reject({{ $inboundEmail->id }})"
                                        wire:target="reject({{ $inboundEmail->id }})"
                                        wire:confirm="Are you sure you want to reject this email? This cannot be undone."
                                    >
                                        Reject
                                    </x-form-button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">
                                No inbound emails need review.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $inboundEmails->links() }}
        </div>
    </x-card>
</div>
