@props(['sermon'])

{{-- Shown beside the recording when only part of the talk was recorded (set in the sermon editor). --}}
@if ($sermon->partial_recording)
    <p {{ $attributes->merge(['class' => 'flex items-start gap-2 text-sm text-gray-600']) }}>
        <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 flex-shrink-0 text-cbc-teal" aria-hidden="true" />
        <span>Only part of this {{ Str::lower($sermon->content_type->label()) }} was recorded.</span>
    </p>
@endif
