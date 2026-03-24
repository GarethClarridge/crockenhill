@props(['slug'])

@if(auth()->user()?->canAccessAdmin())
    <x-edit-buttons :$slug />
@endif
