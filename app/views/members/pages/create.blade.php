@extends('layouts.members')

@section('title', 'Create a New Page')

@section('description', '<meta name="description" content="Create a new page">')

@section('membercontent')

    <div class="header-container"">
        <h1>Create a Page</h1>
    </div>
    <ol class="breadcrumb">
        <li>{{ link_to_route('Home', 'Home') }}</li>
        <li class="active">Log In</li>
    </ol>
 
    <h2>Create new page</h2>

    {{ Form::open(array('route' => 'members.pages.store')) }}

        <div class="control-group">
            {{ Form::label('title', 'Title') }}
            <div class="controls">
                {{ Form::text('title') }}
            </div>
        </div>

        <div class="control-group">
            {{ Form::label('parent', 'Parent') }}
            <div class="controls">
                {{ Form::text('parent') }}
            </div>
        </div>

        <div class="control-group">
            {{ Form::label('description', 'Description') }}
            <div class="controls">
                {{ Form::text('description') }}
            </div>
        </div>

        <div class="control-group">
            {{ Form::label('body', 'Content') }}
            <div class="controls">
                {{ Form::textarea('body') }}
            </div>
        </div>

        <div class="form-actions">
            {{ Form::submit('Save', array('class' => 'btn btn-success btn-save btn-large')) }}
            <a href="{{ URL::route('members.pages.index') }}" class="btn btn-large">Cancel</a>
        </div>

    {{ Form::close() }}
 
@stop
