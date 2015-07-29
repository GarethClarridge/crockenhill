@extends('pages.page')

@section('dynamic_content')

<a href="sermons/create" class="btn btn-default btn-lg btn-block" role="button">Upload a new sermon</a>

<h2>Edit an existing sermon</h2>

    <div>
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Preacher</th>
                    <th>Date</th>
                    <th>Service</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($sermons as $sermon)
                    <tr>
                        <td>
                            {{ $sermon->title }}
                        </td>
                        <td>{{ $sermon->preacher }}</td>
                        <td>{{ $sermon->date}}</td>
                        <td>
                            @if (substr($sermon->filename, -1) === 'a')
                                Morning
                            @elseif (substr($sermon->filename, -1) === 'b')
                                Evening
                            @else
                                Other
                            @endif
                        </td>
                        <td>
                            <a href="{{ URL::route('members.sermons.edit', $sermon->slug) }}" class="btn btn-success">Edit</a>
                            {{ Form::open(array('route' => array('members.sermons.destroy', $sermon->slug), 'method' => 'delete', 'data-confirm' => 'Are you sure you want to delete this sermon?', 'class' => 'form-inline')) }}
                            <button type="submit" href="{{ URL::route('members.sermons.destroy', $sermon->slug) }}" class="btn btn-danger">
                                Delete
                            </button>
                            {{ Form::close() }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
 
@stop
