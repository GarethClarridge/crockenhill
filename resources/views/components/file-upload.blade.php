@props([
    'name',
    'label',
])

<div class="mb-3">
    <label class="mb-2 inline-block" 
            for="{{$name}}">
        {{ $label }}
    </label>
    <input aria-describedby="{{$name}}" 
            id="{{$name}}" 
            type="file"
            name="{{$name}}"
            class="block w-full rounded-lg bg-white
                            focus:z-10 focus:border-blue-500 focus:ring-blue-500 
                            disabled:opacity-50 disabled:pointer-events-none 
                            file:bg-gray-300 file:me-4 file:border-0
                            file:py-3 file:px-4">
</div>