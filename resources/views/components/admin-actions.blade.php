@props(['editRoute', 'deleteRoute', 'deleteConfirmMessage' => 'Are you sure you want to delete this item?', 'layout' => 'grid', 'withIcons' => false])

@if($layout === 'grid')
<form method="POST" action="{{ $deleteRoute }}" accept-charset="UTF-8" class="-mt-6 grid grid-cols-2">
  @csrf
  <a href="{{ $editRoute }}" 
     class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-bl-md bg-cbc-pattern bg-cover focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
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
          class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-br-md bg-gradient-to-r from-rose-600 to-rose-700 focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
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
  <div class="relative inline-flex align-middle">
    <a href="{{ $editRoute }}" 
       class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-green-500 hover:bg-green-600 text-white">
      Edit
    </a>
    <button type="submit" 
            onclick="return confirm('{{ $deleteConfirmMessage }}')"
            class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-red-600 hover:bg-red-700 text-white">
      Delete
    </button>
  </div>
</form>
@endif