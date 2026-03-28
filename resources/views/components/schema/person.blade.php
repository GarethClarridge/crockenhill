@props([
    'person',
])

@php
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Str;

    $imageUrl = null;
    if ($person->image_path) {
        $imageUrl = (Str::startsWith($person->image_path, ['http://', 'https://', '/']))
            ? $person->image_path
            : Storage::disk('public')->url($person->image_path);
    }

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        'name' => $person->name,
        'url' => url("/christ/sermons/preachers/{$person->slug}"),
        'worksFor' => [
            '@type' => 'Organization',
            'name' => config('organization.name'),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('images/Primary.png'),
            ],
        ],
    ];

    if ($person->bio) {
        $schema['description'] = $person->bio;
    }

    if ($imageUrl) {
        $schema['image'] = $imageUrl;
    }
@endphp

<script type="application/ld+json">
    {!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
