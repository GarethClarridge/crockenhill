@extends('layouts.members')

@section('title')
{{ $song->title }} - Songs - Crockenhill Baptist Church
@stop

@section('description', '<meta name="description" content="Songs - Crockenhill Baptist Church">')

@section('breadcrumbs')
    <li> {{ link_to_route('Members', 'Members') }} </li><li> {{ link_to_route('songs', 'Songs') }} </li><li class="active"> {{ $song->title }} </li>
@stop

@section('header')
    <h1> {{ $song->title }} </h1>
@stop

@section('main-content')

    <div class="table-responsive">
        <table class="table">
            <tr>
                @if ($song->praise_number != '')
                    <td>Praise Number</td>
                    <td> {{ $song->praise_number }} </td>
                @endif
            </tr>
            <tr>
                @if ($song->author != '')
                    <td>Author(s)</td>
                    <td> {{ $song->author }} </td>
                @endif
            </tr>
            <tr>
                @if ($song->copyright != '')
                    <td>Copyright</td>
                    <td> {{ $song->copyright }} </td>
                @endif
            </tr>
            <tr>
                @if ($last_played_date != '')
                    <td>Last Sung</td>
                    <td> {{ $last_played_date }}, at the {{ $last_played_service }} service. </td>
                @endif
            </tr>
            <tr>
                @if ($frequency != '')
                    <td>Familiarity</td>

                    @if ($frequency < 1)
                        <td class="danger">
                    @elseif ($frequency <= 5 && $frequency != 0)
                        <td class="warning">
                    @elseif ($frequency > 5)
                        <td class="success">
                    @endif

                        Sung {{ $frequency }} time(s) in the last 3 years.</td>
                @endif
            </tr>
            <tr>
                @if ($song->lyrics != '')
                    <td>Lyrics</td>
                    <td> {{ nl2br($song->lyrics) }} </td>
                @endif
            </tr>
        </table>
    </div>

@stop
