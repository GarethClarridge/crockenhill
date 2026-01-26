@props([
    'area' => null,
    'heading' => null,
    'slug' => null,
    'editable' => false,
])

<div class="inline-flex items-center justify-between w-full flex-wrap">
    @if ($area && $heading)
        <x-breadcrumbs :$area :$heading />
    @endif

    @if ($editable && $slug)
        <x-edit-buttons :$slug />
    @endif
</div>
