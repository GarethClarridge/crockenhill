@props(['cols' => 1])

<div class="px-6 grid grid-cols-{{ $cols }} gap-2 max-w-2xl mx-auto mt-6">
  {{ $slot }}
</div>