@extends('layouts/page')

@section('dynamic_content')

  {{-- Replace static form with Livewire component --}}
  @livewire('media-upload')

@stop
