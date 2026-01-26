@props([
    'heading',
    'picture' => null,
    'pictureMobile' => null,
    'pictureTablet' => null,
])

@php
    $hasHeadingPicture = $picture && (
        str_starts_with($picture, 'http') ||
        str_starts_with($picture, '//') ||
        file_exists($_SERVER['DOCUMENT_ROOT'] . $picture)
    );
@endphp

@if ($hasHeadingPicture)
    <x-h1-picture
        :headingpicture="$picture"
        :headingpicture-mobile="$pictureMobile"
        :headingpicture-tablet="$pictureTablet"
    >
        {{ $heading }}
    </x-h1-picture>
@else
    <x-h1>
        {{ $heading }}
    </x-h1>
@endif
