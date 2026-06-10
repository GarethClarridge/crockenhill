@props(['sermon'])

@if(auth()->user()?->canAccessAdmin())
    <div class="mt-auto border-t border-gray-100">
        <form
            method="POST"
            action="/christ/sermons/{{ $sermon->date->format('Y') }}/{{ $sermon->date->format('m') }}/{{ $sermon->slug }}/delete"
            accept-charset="UTF-8"
            class="grid grid-cols-2"
        >
            @csrf
            <a
                href="{{ route('admin.sermons.edit', $sermon->slug) }}"
                wire:navigate
                class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-bl-md bg-cbc-pattern bg-size-cover focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2 transition-all"
            >
                <div class="flex items-center justify-center">
                    <x-heroicon-s-pencil-square class="h-6 w-6 mr-2" />
                    Edit
                </div>
            </a>
            <button
                type="submit"
                onclick="return confirm('Are you sure you want to delete this sermon?')"
                class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-br-md bg-gradient-to-r from-rose-600 to-rose-700 focus:outline-none focus:ring-2 focus:ring-cbc-teal focus:ring-offset-2 transition-all"
            >
                <div class="flex items-center justify-center">
                    <x-heroicon-s-trash class="h-6 w-6 mr-2" />
                    Delete
                </div>
            </button>
        </form>
    </div>
@endif
