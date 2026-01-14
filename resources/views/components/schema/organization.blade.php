{{-- Organization Schema.org JSON-LD markup for SEO --}}
<script type="application/ld+json">
{!! json_encode([
  '@context' => 'https://schema.org',
  '@type' => 'Church',
  'name' => 'Crockenhill Baptist Church',
  '@id' => 'https://crockenhill.org',
  'url' => 'https://crockenhill.org',
  'logo' => asset('images/Primary.png'),
  'description' => 'An independent evangelical church in Crockenhill, Kent. Worshipping God, strengthening believers, and proclaiming Jesus Christ to all.',
  'address' => [
    '@type' => 'PostalAddress',
    'streetAddress' => 'Eynsford Road',
    'addressLocality' => 'Crockenhill',
    'addressRegion' => 'Kent',
    'postalCode' => 'BR8 8JS',
    'addressCountry' => 'GB'
  ],
  'geo' => [
    '@type' => 'GeoCoordinates',
    'latitude' => '51.38349261524606',
    'longitude' => '0.16404725602797054'
  ],
  'telephone' => '+44-1322-663995',
  'email' => 'admin@crockenhill.org',
  'sameAs' => [
    'https://www.facebook.com/pages/Crockenhill-Baptist-Church/487590057946905',
    'https://www.youtube.com/@crockenhillbaptistchurch9727/streams'
  ]
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
