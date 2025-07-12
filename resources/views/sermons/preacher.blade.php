@extends('layouts/page')

@section('full_width_content')

  <x-sermon-list :sermons="$sermons" :groupedByDate="false" />


@stop
