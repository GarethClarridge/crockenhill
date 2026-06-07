<x-mail::message>
# Route canary failure

One or more public route types are failing in production:

@foreach ($failures as $url => $reason)
- **{{ $url }}** — {{ $reason }}
@endforeach

<x-mail::button :url="config('app.url')">
Open site
</x-mail::button>

Thanks,<br>
{{ config('app.name') }} monitoring
</x-mail::message>
