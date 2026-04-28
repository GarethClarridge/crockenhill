<x-mail::message>
# New Bible Request

Someone has requested a free Bible through the website.

**Name:** {{ $name }}

**Address:** {{ $address }}

@if($email ?? null)
**Email:** {{ $email }}
@endif

@if($phone ?? null)
**Phone:** {{ $phone }}
@endif

@if($note ?? null)
**Note from requester:** {{ $note }}
@endif

Thanks,<br>
{{ config('app.name') }} Website
</x-mail::message>
