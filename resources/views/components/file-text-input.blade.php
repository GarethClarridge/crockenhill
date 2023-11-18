@props([
    'name',
    'label',
])

<div class="mb-3">
    <label for="{{$name}}">{{$label}}</label>
    <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded h1" 
            id="{{$name}}" 
            name="{{$name}}" 
            type="text">
</div>