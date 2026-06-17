@props([
    'title',
    'description' => null,
    'type' => 'website',
    'image' => null,
    'imageWidth' => 800,
    'imageHeight' => 600,
    'imageAlt' => null,
    'canonical' => null,
    'audio' => null,
    'video' => null,
    'author' => null,
    'publishedTime' => null,
    'modifiedTime' => null,
    'section' => null,
    'tags' => null,
    'label1' => null,
    'data1' => null,
    'label2' => null,
    'data2' => null,
    'twitterSite' => null,
    'twitterCreator' => null,
])

@php
    $siteName = 'Crockenhill Baptist Church';
    $fullTitle = $title === $siteName ? $title : "{$title} | {$siteName}";
    $metaDescription = $description ?? $title;
    $metaImage = $image ?? asset('images/Primary.png');
    $metaUrl = $canonical ?? url()->current();
    $twitterCard = $image ? 'summary_large_image' : 'summary';
    $resolvedImageAlt = $imageAlt ?? ($image ? null : 'Crockenhill Baptist Church logo');
@endphp

{{-- Open Graph meta tags --}}
<meta property="og:title" content="{{ $fullTitle }}">
<meta property="og:description" content="{{ $metaDescription }}">
<meta property="og:type" content="{{ $type }}">
<meta property="og:url" content="{{ $metaUrl }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:locale" content="en_GB">
<meta property="og:image" content="{{ $metaImage }}">
<meta property="og:image:width" content="{{ $imageWidth }}">
<meta property="og:image:height" content="{{ $imageHeight }}">
@if($resolvedImageAlt)
<meta property="og:image:alt" content="{{ $resolvedImageAlt }}">
@endif

@if($audio)
<meta property="og:audio" content="{{ $audio }}">
<meta property="og:audio:type" content="audio/mpeg">
@endif

@if($video)
<meta property="og:video" content="{{ $video }}">
<meta property="og:video:type" content="video/mp4">
@endif

@if($type === 'article')
@if($author)
<meta property="article:author" content="{{ str_starts_with($author, 'http') ? $author : url($author) }}">
@endif
@if($publishedTime)
<meta property="article:published_time" content="{{ $publishedTime }}">
@endif
@if($modifiedTime)
<meta property="article:modified_time" content="{{ $modifiedTime }}">
@endif
@if($section)
<meta property="article:section" content="{{ $section }}">
@endif
@if($tags)
@foreach(\Illuminate\Support\Arr::wrap($tags) as $tag)
<meta property="article:tag" content="{{ $tag }}">
@endforeach
@endif
@elseif($type === 'profile')
@if($author)
<meta property="profile:username" content="{{ $author }}">
@endif
@endif

{{-- Twitter Card meta tags --}}
<meta name="twitter:card" content="{{ $twitterCard }}">
<meta name="twitter:title" content="{{ $fullTitle }}">
<meta name="twitter:description" content="{{ $metaDescription }}">
<meta name="twitter:image" content="{{ $metaImage }}">
@if($twitterSite)
<meta name="twitter:site" content="{{ $twitterSite }}">
@endif
@if($twitterCreator)
<meta name="twitter:creator" content="{{ $twitterCreator }}">
@endif
@if($resolvedImageAlt)
<meta name="twitter:image:alt" content="{{ $resolvedImageAlt }}">
@endif
@if($label1 && $data1)
<meta name="twitter:label1" content="{{ $label1 }}">
<meta name="twitter:data1" content="{{ $data1 }}">
@endif
@if($label2 && $data2)
<meta name="twitter:label2" content="{{ $label2 }}">
<meta name="twitter:data2" content="{{ $data2 }}">
@endif


{{ $slot }}
