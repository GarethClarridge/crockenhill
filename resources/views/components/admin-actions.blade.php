@props(['editRoute', 'deleteRoute', 'deleteConfirmMessage' => 'Are you sure you want to delete this item?', 'layout' => 'grid', 'withIcons' => false])

@if($layout === 'grid')
<form method="POST" action="{{ $deleteRoute }}" accept-charset="UTF-8" class="grid grid-cols-2">
  @csrf
  <a href="{{ $editRoute }}" wire:navigate
     class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-bl-md bg-cbc-pattern bg-cover focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2 transition-all">
    @if($withIcons)
    <div class="flex items-center justify-center">
      <x-heroicon-s-pencil-square class="h-6 w-6 mr-2" />
      Edit
    </div>
    @else
    Edit
    @endif
  </a>
  <button type="submit" 
          onclick="return confirm('{{ $deleteConfirmMessage }}')"
          class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-br-md bg-gradient-to-r from-rose-600 to-rose-700 focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2 transition-all">
    @if($withIcons)
    <div class="flex items-center justify-center">
      <x-heroicon-s-trash class="h-6 w-6 mr-2" />
      Delete
    </div>
    @else
    Delete
    @endif
  </button>
</form>
@else
<form class="flex items-center gap-1" action="{{ $deleteRoute }}" method="POST">
  @csrf
  @method('DELETE')
  <x-button :link="$editRoute" variant="outline" size="sm" inline>Edit</x-button>
  <x-form-button variant="danger" size="sm" onclick="return confirm('{{ $deleteConfirmMessage }}')">Delete</x-form-button>
</form>
@endif