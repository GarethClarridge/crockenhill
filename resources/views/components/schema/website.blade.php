{{-- WebSite Schema.org JSON-LD markup for SEO --}}
@php
    $schema = [
        '@' . 'context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => config('organization.name'),
        '@id' => config('app.url').'/',
        'url' => config('app.url'),
    ];
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
