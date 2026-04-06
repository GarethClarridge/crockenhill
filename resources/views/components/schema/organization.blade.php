{{-- Organization Schema.org JSON-LD markup for SEO --}}
<script type="application/ld+json">
{!! json_encode([
  '@context' => 'https://schema.org',
  '@type' => 'Church',
  'name' => config('organization.name'),
  '@id' => config('app.url'),
  'url' => config('app.url'),
  'logo' => asset('images/Primary.png'),
  'description' => config('organization.description'),
  'address' => [
    '@type' => 'PostalAddress',
    'streetAddress' => config('organization.address.street'),
    'addressLocality' => config('organization.address.locality'),
    'addressRegion' => config('organization.address.region'),
    'postalCode' => config('organization.address.postal_code'),
    'addressCountry' => config('organization.address.country'),
  ],
  'geo' => [
    '@type' => 'GeoCoordinates',
    'latitude' => config('organization.geo.latitude'),
    'longitude' => config('organization.geo.longitude'),
  ],
  'telephone' => config('organization.phone'),
  'email' => config('organization.email_admin'),
  'sameAs' => array_values(config('organization.social')),
  'openingHoursSpecification' => collect(config('organization.services'))->map(fn ($service) => [
    '@type' => 'OpeningHoursSpecification',
    'dayOfWeek' => $service['day'],
    'opens' => $service['opens'],
    'closes' => $service['closes'],
  ])->values()->all(),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
