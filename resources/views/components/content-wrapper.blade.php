@props([
'class' => 'mx-auto max-w-2xl xl:max-w-3xl px-12 md:px-6',
])

<div {{ $attributes->merge(['class' => $class]) }}>
  {{ $slot }}
</div>