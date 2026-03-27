@props([
    'preacher',
])

@php
    use Illuminate\Support\Str;

    $bio = $preacher->bio ? trim(strip_tags($preacher->bio)) : null;
    $imageUrl = $preacher->image_path
        ? (Str::startsWith($preacher->image_path, ['http://', 'https://', '/'])
            ? $preacher->image_path
            : \Illuminate\Support\Facades\Storage::disk('public')->url($preacher->image_path))
        : null;

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        'name' => $preacher->name,
        'url' => url("/christ/sermons/preachers/{$preacher->slug}"),
        'worksFor' => [
            '@type' => 'Organization',
            'name' => config('organization.name'),
            'url' => url('/'),
        ],
    ];

    if ($bio) {
        $schema['description'] = Str::limit($bio, 155);
    }

    if ($imageUrl) {
        $schema['image'] = $imageUrl;
    }
@endphp

<script type="application/ld+json">
    {!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
