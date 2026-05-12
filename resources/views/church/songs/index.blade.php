@extends('layouts.page')

@section('title', 'Songs')

@section('meta_description', 'Browse the songs most often sung at Crockenhill Baptist Church.')

@section('meta_tags')
<x-meta-tags
    title="Songs"
    description="Browse the songs most often sung at Crockenhill Baptist Church."
/>
<x-schema.webpage
    heading="Songs"
    description="Browse the songs most often sung at Crockenhill Baptist Church."
/>

<x-breadcrumbs :area="$area" :heading="$heading" json-only />
@endsection

@section('dynamic_content')
    <livewire:church.songs.browse-songs />
@endsection
