@if (isset($slug))
@if (auth()->user()?->canAccessAdmin())
<div class="m-6">
  <x-button link="{{ route('admin.pages.edit', ['page' => $slug]) }}">
    <div class="flex items-center justify-center">
      <x-heroicon-s-pencil-square class="h-6 w-6 mr-2" aria-hidden="true" />
      Edit page
    </div>
  </x-button>
</div>
@endif
@endif
