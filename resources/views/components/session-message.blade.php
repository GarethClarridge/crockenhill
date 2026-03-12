<div x-data="{ show: true }" x-show="show" x-cloak x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="relative p-3 my-3 border rounded bg-green-200 border-green-300 text-green-800 block" role="alert">
  <span>{{$slot}}</span>
  <button type="button" @click="show = false" class="absolute top-0 bottom-0 right-0 px-4 py-3 text-green-800 hover:text-green-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 rounded-md transition-all" aria-label="Close">
    <svg class="fill-current h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><title>Close</title><path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/></svg>
  </button>
</div>
