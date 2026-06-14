<x-admin.page
    title="Review inbox"
    description="Everything that needs a human, grouped by service"
>
    <x-slot:actions>
        <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
            Back to services
        </x-button>
    </x-slot:actions>

    {{-- Filter chips --}}
    <div class="flex flex-wrap gap-2" role="group" aria-label="Filter inbox by item type">
        @foreach($filterChips as $chip)
            <button
                type="button"
                wire:click="$set('filter', '{{ $chip['key'] }}')"
                wire:key="inbox-filter-{{ $chip['key'] }}"
                aria-pressed="{{ $filter === $chip['key'] ? 'true' : 'false' }}"
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 {{ $filter === $chip['key'] ? 'bg-cbc-teal text-white' : 'bg-white border border-gray-300 text-gray-700 hover:bg-gray-50' }}"
            >
                {{ $chip['label'] }}
                <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-xs font-semibold {{ $filter === $chip['key'] ? 'bg-white/20 text-white' : 'bg-gray-100 text-gray-600' }}">
                    {{ $chip['count'] }}
                </span>
            </button>
        @endforeach
    </div>

    @if($overflowNotices !== [])
        <p class="mt-3 text-sm text-gray-500">
            @foreach($overflowNotices as $notice)
                Showing the newest {{ $notice['shown'] }} of {{ $notice['total'] }} {{ $notice['label'] }}.
            @endforeach
            Action items to surface the rest.
        </p>
    @endif

    <div class="mt-6 space-y-6" wire:loading.class.delay.200ms="opacity-50">
        @forelse($groups as $group)
            <x-card wire:key="inbox-group-{{ $group['key'] }}">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-3">
                    <h2 class="font-display text-lg text-gray-900">
                        {{ $group['date_label'] }}{{ $group['service_label'] !== '' ? ' — '.$group['service_label'] : '' }}
                    </h2>
                    @if($group['service'] !== null)
                        <x-button link="{{ route('admin.services.show', $group['service']) }}" variant="ghost" size="xs" icon="arrow-right" iconPosition="trailing" inline>
                            Open service
                        </x-button>
                    @elseif($group['date'] !== null && $group['service_value'] !== null)
                        {{-- Orphan group: a resolved date/slot with no service record yet.
                             Creating the Sunday gives its sections a workbench to be edited on --}}
                        <x-button :link="route('admin.services.create', ['date' => $group['date'], 'service' => $group['service_value']])" variant="ghost" size="xs" icon="plus" inline>
                            Create this service
                        </x-button>
                    @endif
                </div>

                <ul class="divide-y divide-gray-100">
                    @foreach($group['items'] as $index => $item)
                        <li class="py-4" wire:key="inbox-item-{{ $group['key'] }}-{{ $item['kind'] }}-{{ $index }}">
                            @if($item['kind'] === 'email')
                                @php $preview = $item['preview']; @endphp
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="min-w-0 space-y-1">
                                        <p class="flex items-center gap-2 text-sm font-medium text-gray-900">
                                            <x-heroicon-o-envelope class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                            {{ $item['email']->subject ?: '(no subject)' }}
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            From {{ $item['email']->from }} · received {{ $item['email']->received_at?->format('j M Y H:i') }}
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            {{ count($preview['preview_items']) }} parsed {{ \Illuminate\Support\Str::plural('item', count($preview['preview_items'])) }}
                                            @if($preview['confidence_score'] !== null)
                                                · {{ round($preview['confidence_score'] * 100) }}% confidence
                                            @endif
                                            @if($preview['warnings'] !== [])
                                                · {{ count($preview['warnings']) }} {{ \Illuminate\Support\Str::plural('warning', count($preview['warnings'])) }}
                                            @endif
                                        </p>
                                        @if($preview['failure_message'] !== null)
                                            <p class="text-xs text-rose-700">{{ $preview['failure_message'] }}</p>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if($preview['can_approve'])
                                            <x-form-button size="xs" variant="primary" icon="check" wire:click="approveEmail({{ $item['email']->id }})" loading-label="Approving...">
                                                Approve
                                            </x-form-button>
                                        @endif
                                        <x-form-button size="xs" variant="outline" icon="pencil-square" wire:click="editAndApproveEmail({{ $item['email']->id }})" loading-label="Loading editor...">
                                            Edit &amp; approve
                                        </x-form-button>
                                        <x-form-button size="xs" variant="outline" icon="arrow-path" wire:click="reparseEmail({{ $item['email']->id }})" loading-label="Re-parsing...">
                                            Re-parse
                                        </x-form-button>
                                        <x-form-button
                                            size="xs"
                                            variant="danger"
                                            icon="x-mark"
                                            wire:click="rejectEmail({{ $item['email']->id }})"
                                            wire:confirm="Are you sure you want to reject this email? This cannot be undone."
                                            loading-label="Rejecting..."
                                        >
                                            Reject
                                        </x-form-button>
                                    </div>
                                </div>

                                {{-- Diagnostics ported from the retired inbound-emails page: the
                                     sanitised original email and raw parser data, for judging a
                                     failed or low-confidence parse before re-parsing or rejecting --}}
                                <details class="mt-3 rounded-lg border border-gray-200 bg-gray-50 text-left" wire:key="inbox-email-diagnostics-{{ $item['email']->id }}">
                                    <summary class="list-none cursor-pointer px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 [&::-webkit-details-marker]:hidden">
                                        <span class="flex items-center justify-between gap-3">
                                            <span>Original email</span>
                                            <span class="text-xs font-normal text-gray-500">Plain text, sanitised HTML, and parser data</span>
                                        </span>
                                    </summary>

                                    <div class="space-y-4 border-t border-gray-200 px-3 py-3">
                                        <div class="grid gap-4 xl:grid-cols-2">
                                            <section class="space-y-2">
                                                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Plain Text</h3>

                                                @if($preview['has_plain_body'])
                                                    <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-md border border-gray-200 bg-white px-3 py-3 font-mono text-xs leading-5 text-gray-700">{{ $preview['plain_body'] }}</pre>
                                                @else
                                                    <p class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-3 text-xs text-gray-500">
                                                        No plain-text body stored.
                                                    </p>
                                                @endif
                                            </section>

                                            <section class="space-y-2">
                                                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">HTML Preview</h3>

                                                @if(is_string($preview['sanitized_html']))
                                                    <div class="prose prose-sm max-w-none rounded-md border border-gray-200 bg-white px-3 py-3 text-gray-700">
                                                        {!! $preview['sanitized_html'] !!}
                                                    </div>
                                                @elseif($preview['has_html_body'])
                                                    <p class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-3 text-xs text-gray-500">
                                                        Stored HTML contained no safe renderable content after sanitisation.
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

                                                @if(is_string($preview['raw_warnings_json']))
                                                    <pre class="max-h-56 overflow-auto rounded-md border border-gray-200 bg-slate-950 px-3 py-3 font-mono text-xs leading-5 text-slate-100">{{ $preview['raw_warnings_json'] }}</pre>
                                                @else
                                                    <p class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-3 text-xs text-gray-500">
                                                        No parser warnings recorded.
                                                    </p>
                                                @endif
                                            </section>

                                            <section class="space-y-2">
                                                <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Raw Parser Metadata</h3>

                                                @if(is_string($preview['raw_parsing_json']))
                                                    <pre class="max-h-56 overflow-auto rounded-md border border-gray-200 bg-slate-950 px-3 py-3 font-mono text-xs leading-5 text-slate-100">{{ $preview['raw_parsing_json'] }}</pre>
                                                @else
                                                    <p class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-3 text-xs text-gray-500">
                                                        No parser metadata stored yet.
                                                    </p>
                                                @endif
                                            </section>
                                        </div>
                                    </div>
                                </details>
                            @elseif($item['kind'] === 'section')
                                @php $section = $item['section']; @endphp
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="min-w-0 space-y-1">
                                        <p class="flex items-center gap-2 text-sm font-medium text-gray-900">
                                            <x-heroicon-o-rectangle-stack class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                            {{ $section->title ?: $section->section_type->label() }}
                                        </p>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($item['reasons'] as $reason)
                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $reason['classes'] }}">{{ $reason['label'] }}</span>
                                            @endforeach
                                        </div>
                                        @if($item['review_reason'] !== null)
                                            <p class="text-xs text-gray-500">{{ $item['review_reason'] }}</p>
                                        @endif
                                        <p class="space-x-3 text-xs">
                                            @if($item['audio_url'] !== null)
                                                <a href="{{ $item['audio_url'] }}" target="_blank" rel="noopener" class="text-cbc-teal-dark underline hover:no-underline">Audio preview</a>
                                            @endif
                                            @if($item['video_url'] !== null)
                                                <a href="{{ $item['video_url'] }}" target="_blank" rel="noopener" class="text-cbc-teal-dark underline hover:no-underline">Video preview</a>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::PendingApproval)
                                            <x-form-button size="xs" variant="primary" icon="check" wire:click="approve({{ $section->id }})" loading-label="Approving...">
                                                Approve
                                            </x-form-button>
                                            <x-form-button size="xs" variant="danger" icon="x-mark" wire:click="reject({{ $section->id }})" wire:confirm="Reject this section?" loading-label="Rejecting...">
                                                Reject
                                            </x-form-button>
                                        @elseif($section->publication_status === \App\Enums\ServiceSectionPublicationStatus::Rejected)
                                            <x-form-button size="xs" variant="outline" icon="arrow-uturn-left" wire:click="requeue({{ $section->id }})" loading-label="Re-queueing...">
                                                Requeue
                                            </x-form-button>
                                        @endif
                                        @if($group['service'] instanceof \App\Models\ChurchService)
                                            <x-button link="{{ route('admin.services.show', $group['service']).'#section-'.$section->id }}" variant="ghost" size="xs" icon="arrow-top-right-on-square" inline aria-label="Edit this section on the service workbench">
                                                Edit
                                            </x-button>
                                        @endif
                                    </div>
                                </div>
                            @elseif($item['kind'] === 'segment')
                                @php $run = $item['run']; @endphp
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="min-w-0 space-y-1">
                                        <p class="flex items-center gap-2 text-sm font-medium text-gray-900">
                                            <x-heroicon-o-video-camera class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                            {{ $run->original_filename }}
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            Sermon segment needs confirming
                                            @if(($item['run']->manualReviewMetadata()['reason_message'] ?? null) !== null)
                                                · {{ $item['run']->manualReviewMetadata()['reason_message'] }}
                                            @endif
                                        </p>
                                    </div>
                                    {{-- Prefer the workbench when the run matched a service (it renders every
                                         segmentation-pipeline run); the standalone page stays for orphan runs --}}
                                    <x-button
                                        link="{{ $group['service'] instanceof \App\Models\ChurchService
                                            ? route('admin.services.show', $group['service']).'#processing-run-'.$run->id
                                            : route('admin.services.processing.review', $run) }}"
                                        variant="primary"
                                        size="xs"
                                        icon="arrow-right"
                                        iconPosition="trailing"
                                        inline
                                    >
                                        Choose segment
                                    </x-button>
                                </div>
                            @elseif($item['kind'] === 'merge')
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="min-w-0 space-y-1">
                                        <p class="flex items-center gap-2 text-sm font-medium text-gray-900">
                                            <x-heroicon-o-arrows-pointing-in class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                            Pending structure merge
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            Incoming {{ strtoupper((string) $item['service']->pending_structure_merge_source) }} plan conflicts with the current order of service.
                                        </p>
                                    </div>
                                    <x-button link="{{ route('admin.services.show', $item['service']) }}" variant="primary" size="xs" icon="arrow-right" iconPosition="trailing" inline>
                                        Resolve
                                    </x-button>
                                </div>
                            @elseif($item['kind'] === 'service_flag')
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="min-w-0 space-y-1">
                                        <p class="flex items-center gap-2 text-sm font-medium text-gray-900">
                                            <x-heroicon-o-flag class="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                                            Service flagged for review
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            Check the imported order of service, then mark it reviewed.
                                        </p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-form-button size="xs" variant="outline" icon="check" wire:click="markServiceReviewed({{ $item['service']->id }})" loading-label="Marking reviewed...">
                                            Mark reviewed
                                        </x-form-button>
                                        <x-button link="{{ route('admin.services.show', $item['service']) }}" variant="ghost" size="xs" icon="arrow-right" iconPosition="trailing" inline>
                                            Open service
                                        </x-button>
                                    </div>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @empty
            <div class="flex flex-col items-center justify-center space-y-3 border-2 border-dashed border-gray-200 rounded-xl bg-gray-50/50 p-8 transition-colors hover:border-gray-300">
                <div class="rounded-full bg-white shadow-sm ring-1 ring-gray-200 p-3">
                    <x-heroicon-o-check-circle class="h-8 w-8 text-cbc-teal/60" aria-hidden="true" />
                </div>
                <h2 class="text-base font-semibold text-gray-900">All caught up</h2>
                <p class="text-sm text-gray-500 max-w-sm mx-auto">
                    There are no items currently requiring manual review.
                </p>
                <div class="mt-4">
                    <x-button link="{{ route('admin.services.index') }}" variant="outline" inline>
                        Back to services
                    </x-button>
                </div>
            </div>
        @endforelse
    </div>
</x-admin.page>
