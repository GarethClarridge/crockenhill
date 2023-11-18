@props([
    'link',
    'title',
])

<iframe 
    class="mx-auto mt-3 mb-6 w-full aspect-video md:w-3/4 lg:w-2/3 xl:w-1/2 py-4"
    loading="lazy"
    src="{{ $link }}" 
    title="{{ $title }}"
    frameborder="0" 
    allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" 
    allowfullscreen>
</iframe>