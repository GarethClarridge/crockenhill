@extends('admin._layouts.default')
 
@section('main')
    <h2>Display sermon</h2>
 
    <hr>
 
    <h3>{{ $sermon->title }}</h3>
    <h5>@{{ $sermon->created_at }}</h5>
    {{ $sermon->body }}
 
    @if ($sermon->image)
        <hr>
        <figure><img src="{{ Image::resize($sermon->image, 800, 600) }}" alt=""></figure>
    @endif
@stop
