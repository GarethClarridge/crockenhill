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
<form class="flex items-center" action="{{ $deleteRoute }}" method="POST">
  @csrf
  @method('DELETE')
  <div class="relative inline-flex align-middle gap-1">
    <a href="{{ $editRoute }}" wire:navigate
       class="inline-block text-center select-none border font-normal whitespace-nowrap rounded py-1 px-3 leading-normal no-underline bg-green-500 hover:bg-green-600 text-white focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-1 transition-all">
      Edit
    </a>
    <button type="submit" 
            onclick="return confirm('{{ $deleteConfirmMessage }}')"
            class="inline-block text-center select-none border font-normal whitespace-nowrap rounded py-1 px-3 leading-normal no-underline bg-red-600 hover:bg-red-700 text-white focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-1 transition-all">
      Delete
    </button>
  </div>
</form>
@endif