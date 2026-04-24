<x-admin.list-shell
    title="Inbound email review"
    description="Review low-confidence or failed order-of-service emails before they become canonical."
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
            Back to services
        </x-button>
        <x-button link="{{ route('admin.services.upload') }}" variant="outline" icon="arrow-up-tray" inline>
            Upload service
        </x-button>
        <x-button link="{{ route('admin.services.submit-email') }}" variant="outline" icon="envelope" inline>
            Submit email text
        </x-button>
    </x-slot:actions>

    <x-slot:filters>
        <x-admin.filter-bar>
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
        </x-admin.filter-bar>
    </x-slot:filters>

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
                        $review = $reviewData[$inboundEmail->id] ?? [];
                        $previewItems = $review['preview_items'] ?? [];
                        $warnings = $review['warnings'] ?? [];
                        $resolvedDate = $review['resolved_date'] ?? null;
                        $resolvedService = $review['resolved_service'] ?? null;
                        $confidenceScore = $review['confidence_score'] ?? null;
                        $canApprove = (bool) ($review['can_approve'] ?? false);
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
                                    <span><span class="font-medium text-gray-700">Approval:</span> {{ $canApprove ? 'Ready to approve' : 'Needs manual editing' }}</span>
                                    @if(is_string($review['reparsed_at'] ?? null))
                                        <span><span class="font-medium text-gray-700">Re-parsed:</span> {{ \Illuminate\Support\Carbon::parse($review['reparsed_at'])->format('j M Y H:i') }}</span>
                                    @endif
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

                                @if(is_string($review['failure_message'] ?? null))
                                    <p class="text-xs text-rose-700">{{ $review['failure_message'] }}</p>
                                @endif

                                <details class="rounded-lg border border-gray-200 bg-gray-50 text-left">
                                    <summary class="list-none cursor-pointer px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 [&::-webkit-details-marker]:hidden">
                                        <span class="flex items-center justify-between gap-3">
                                            <span>Original email</span>
                                            <span class="text-xs font-normal text-gray-500">Plain text, sanitized HTML, and parser data</span>
                                        </span>
                                    </summary>

                                    <div class="space-y-4 border-t border-gray-200 px-3 py-3">
                                        <div class="grid gap-4 xl:grid-cols-2">
                                            <section class="space-y-2">
                                                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Plain Text</h3>

                                                @if($review['has_plain_body'] ?? false)
                                                    <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-md border border-gray-200 bg-white px-3 py-3 font-mono text-xs leading-5 text-gray-700">{{ $review['plain_body'] }}</pre>
                                                @else
                                                    <p class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-3 text-xs text-gray-500">
                                                        No plain-text body stored.
                                                    </p>
                                                @endif
                                            </section>

                                            <section class="space-y-2">
                                                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">HTML Preview</h3>

                                                @if(is_string($review['sanitized_html'] ?? null))
                                                    <div class="prose prose-sm max-w-none rounded-md border border-gray-200 bg-white px-3 py-3 text-gray-700">
                                                        {!! $review['sanitized_html'] !!}
                                                    </div>
                                                @elseif($review['has_html_body'] ?? false)
                                                    <p class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-3 text-xs text-gray-500">
                                                        Stored HTML contained no safe renderable content after sanitization.
                                                    </p>
                                                @else
                                                    <p class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-3 text-xs text-gray-500">
                                                        No HTML body stored.
                                                    </p>
                                                @endif
                                            </section>
                                        </div>

                                        <div class="grid gap-4 xl:grid-cols-2">
                                            <section class="space-y-2">
                                                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Parser Warnings</h3>

                                                @if(is_string($review['raw_warnings_json'] ?? null))
                                                    <pre class="max-h-56 overflow-auto rounded-md border border-gray-200 bg-slate-950 px-3 py-3 font-mono text-xs leading-5 text-slate-100">{{ $review['raw_warnings_json'] }}</pre>
                                                @else
                                                    <p class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-3 text-xs text-gray-500">
                                                        No parser warnings recorded.
                                                    </p>
                                                @endif
                                            </section>

                                            <section class="space-y-2">
                                                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Raw Parser Metadata</h3>

                                                @if(is_string($review['raw_parsing_json'] ?? null))
                                                    <pre class="max-h-56 overflow-auto rounded-md border border-gray-200 bg-slate-950 px-3 py-3 font-mono text-xs leading-5 text-slate-100">{{ $review['raw_parsing_json'] }}</pre>
                                                @else
                                                    <p class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-3 text-xs text-gray-500">
                                                        No parser metadata stored yet.
                                                    </p>
                                                @endif
                                            </section>
                                        </div>
                                    </div>
                                </details>
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
                                    wire:click="reparse({{ $inboundEmail->id }})"
                                    wire:target="reparse({{ $inboundEmail->id }})"
                                >
                                    Re-parse Email
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
                    <x-admin.empty-state
                        :colspan="4"
                        :hasFilters="$hasFilters"
                        title="No emails to review"
                        description="No inbound emails need review."
                    />
                @endforelse
            </tbody>
        </table>
    </div>

    <x-slot:pagination>
        {{ $inboundEmails->links() }}
    </x-slot:pagination>
</x-admin.list-shell>
