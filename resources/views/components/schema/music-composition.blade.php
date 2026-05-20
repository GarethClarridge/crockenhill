@props([
    'song',
])

@php
    $authors = $song->authors->map(function ($author) {
        return [
            '@type' => 'Person',
            'name' => $author->display_name,
        ];
    })->values()->all();

    $schema = [
        '@' . 'context' => 'https://schema.org',
        '@type' => 'MusicComposition',
        '@id' => route('church.songs.show', $song->slug) . '#song',
        'name' => $song->title,
        'url' => route('church.songs.show', $song->slug),
        'author' => $authors,
    ];
@endphp

<script type="application/ld+json">
    {!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
