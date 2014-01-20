@extends('admin._layouts.default')

@section('main')

        <h1>
                Sermons <a href="{{ URL::route('admin.sermons.create') }}" class="btn btn-success"><i class="icon-plus-sign"></i> Add new sermon</a>
        </h1>

        <hr>

        {{ Notification::showAll() }}

        <table class="table table-striped">
                <thead>
                        <tr>
                                <th>#</th>
                                <th>Title</th>
                                <th>When</th>
                                <th><i class="icon-cog"></i></th>
                        </tr>
                </thead>
                <tbody>
                        @foreach ($sermons as $sermon)
                                <tr>
                                        <td>{{ $sermon->id }}</td>
                                        <td><a href="{{ URL::route('admin.sermons.show', $sermon->id) }}">{{ $sermon->title }}</a></td>
                                        <td>{{ $sermon->created_at }}</td>
                                        <td>
                                                <a href="{{ URL::route('admin.sermons.edit', $sermon->id) }}" class="btn btn-success btn-mini pull-left">Edit</a>

                                                {{ Form::open(array('route' => array('admin.sermons.destroy', $sermon->id), 'method' => 'delete', 'data-confirm' => 'Are you sure?')) }}
                                                        <button type="submit" href="{{ URL::route('admin.sermons.destroy', $sermon->id) }}" class="btn btn-danger btn-mini">Delete</butfon>
                                                {{ Form::close() }}
                                        </td>
                                </tr>
                        @endforeach
                </tbody>
        </table>

@stop
