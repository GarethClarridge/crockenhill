@php($preview = $item['preview'])
@php($hasMultipleServicePlans = count($preview['service_plans']) > 1)

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
            <x-form-button size="xs" variant="primary" icon="check" wire:click="approveEmail({{ $item['email']->id }})" loading-label="Approving...">Approve</x-form-button>
            <x-form-button size="xs" variant="outline" icon="archive-box-arrow-down" wire:click="retainEmailEvidence({{ $item['email']->id }})" wire:confirm="Retain these parsed items as supporting evidence without changing the service running order?" loading-label="Retaining...">Retain as evidence</x-form-button>
        @endif
        @unless($hasMultipleServicePlans)
            {{-- The plan-less editor prefills the primary plan only, so it is offered for single-order
                 emails and legacy flattened parses; multi-order emails get a button per plan below. --}}
            <x-form-button size="xs" variant="outline" icon="pencil-square" wire:click="editAndApproveEmail({{ $item['email']->id }})" loading-label="Loading editor...">Edit &amp; approve</x-form-button>
        @endunless
        <x-form-button size="xs" variant="outline" icon="arrow-path" wire:click="reparseEmail({{ $item['email']->id }})" loading-label="Re-parsing...">Re-parse</x-form-button>
        <x-form-button size="xs" variant="danger" icon="x-mark" wire:click="rejectEmail({{ $item['email']->id }})" wire:confirm="Are you sure you want to reject this email? This cannot be undone." loading-label="Rejecting...">Reject</x-form-button>
    </div>
</div>

@if($preview['is_legacy_flattened'])
    <p class="mt-2 text-xs text-amber-700">Parsed before multi-service support was added — re-parse this email before approving.</p>
@elseif($hasMultipleServicePlans)
    <div class="mt-3 space-y-2" wire:key="attention-email-plans-{{ $item['email']->id }}">
        <p class="text-xs font-medium text-gray-500">This email contains {{ count($preview['service_plans']) }} service orders — edit each order separately:</p>
        @foreach($preview['service_plans'] as $plan)
            <div class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-gray-200 bg-white px-3 py-2" wire:key="attention-email-plan-{{ $item['email']->id }}-{{ $plan['plan_key'] }}">
                <p class="min-w-0 text-xs text-gray-600">
                    <span class="font-medium text-gray-800">{{ ucfirst($plan['service'] ?? 'Unknown') }}</span>
                    · {{ $plan['date'] ?? 'no date' }} · {{ count($plan['preview_items']) }} {{ \Illuminate\Support\Str::plural('item', count($plan['preview_items'])) }}
                    @if($plan['resolved']) · <span class="font-medium text-emerald-700">imported</span> @endif
                </p>
                @unless($plan['resolved'])
                    {{-- Blade compiles echoes but not directives inside component tag attributes, so the
                         plan key must be quoted with an echo of Js::from() rather than with @js(). --}}
                    <x-form-button size="xs" variant="outline" icon="pencil-square" wire:click="editAndApproveEmail({{ $item['email']->id }}, {{ \Illuminate\Support\Js::from($plan['plan_key']) }})" loading-label="Loading editor...">Edit this order</x-form-button>
                @endunless
            </div>
        @endforeach
    </div>
@endif

<details class="mt-3 rounded-lg border border-gray-200 bg-gray-50 text-left" wire:key="attention-email-diagnostics-{{ $item['email']->id }}">
    <summary class="list-none cursor-pointer px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 [&::-webkit-details-marker]:hidden">
        <span class="flex items-center justify-between gap-3"><span>Original email</span><span class="text-xs font-normal text-gray-500">Plain text, sanitised HTML, and parser data</span></span>
    </summary>
    <div class="grid gap-4 border-t border-gray-200 px-3 py-3 xl:grid-cols-2">
        <section class="space-y-2">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Plain text</h4>
            <pre class="max-h-72 overflow-auto whitespace-pre-wrap break-words rounded-md border border-gray-200 bg-white px-3 py-3 font-mono text-xs leading-5 text-gray-700">{{ $preview['has_plain_body'] ? $preview['plain_body'] : 'No plain-text body stored.' }}</pre>
        </section>
        <section class="space-y-2">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500">HTML preview</h4>
            @if(is_string($preview['sanitized_html']))
                <div class="prose prose-sm max-w-none rounded-md border border-gray-200 bg-white px-3 py-3 text-gray-700">{!! $preview['sanitized_html'] !!}</div>
            @else
                <p class="rounded-md border border-dashed border-gray-300 bg-white px-3 py-3 text-xs text-gray-500">No safe HTML preview available.</p>
            @endif
        </section>
        <section class="space-y-2">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Parser warnings</h4>
            <pre class="max-h-56 overflow-auto rounded-md border border-gray-200 bg-slate-950 px-3 py-3 font-mono text-xs leading-5 text-slate-100">{{ $preview['raw_warnings_json'] ?? 'No parser warnings recorded.' }}</pre>
        </section>
        <section class="space-y-2">
            <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Raw parser metadata</h4>
            <pre class="max-h-56 overflow-auto rounded-md border border-gray-200 bg-slate-950 px-3 py-3 font-mono text-xs leading-5 text-slate-100">{{ $preview['raw_parsing_json'] ?? 'No parser metadata stored yet.' }}</pre>
        </section>
    </div>
</details>
