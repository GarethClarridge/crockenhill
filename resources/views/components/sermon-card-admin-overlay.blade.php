@props(['sermon'])

@if(auth()->user()?->canAccessAdmin())
    <div class="mt-auto border-t border-gray-100">
        <a
            href="{{ route('admin.sermons.edit', $sermon->slug) }}"
            wire:navigate
            class="block w-full max-w-md p-4 mx-auto text-center text-white no-underline rounded-md bg-cbc-pattern bg-size-cover focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2 transition-all"
        >
                <div class="flex items-center justify-center">
                    <x-heroicon-s-pencil-square class="h-6 w-6 mr-2" aria-hidden="true" />
                    Edit
                </div>
        </a>
    </div>
@endif
