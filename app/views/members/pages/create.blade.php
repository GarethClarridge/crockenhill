@extends('layouts.members')

@section('title', 'Create a New Page')

@section('description', '<meta name="description" content="Create a new page">')

@section('membercontent')

    {{ Form::open(array('route' => 'members.pages.store', 'files' => true)) }}

        <div class="form-group">
            {{ Form::label('heading', 'Heading') }}
            {{ Form::text('heading', $value = null, array('class' => 'form-control')) }}
        </div>

            <div class="form-group">
              {{ Form::label('image', 'Image') }}
              {{ Form::file('image') }}
            </div>

        <div class="form-group">
            {{ Form::label('area', 'Website Area') }}
            {{ Form::select('area', array(
                'about-us'      => 'About Us', 
                'whats-on'      => 'What\'s On', 
                'find-us'       => 'Find Us', 
                'contact-us'    => 'Contact Us',
                'sermons'       => 'Sermons',
                'members'       => 'Members'
                ), $value = null, array('class' => 'form-control')) }}
        </div>

        <div class="form-group">
            {{ Form::label('description', 'Description') }}
            {{ Form::text('description', $value = null, array('class' => 'form-control')) }}
        </div>

        <div class="form-group">
            {{ Form::label('body', 'Content') }}
            {{ Form::textarea('body', $value = null, array('class' => 'form-control')) }}
        </div>
        <div class="form-actions">
            {{ Form::submit('Save', array('class' => 'btn btn-success btn-save btn-large')) }}
            <a href="{{ URL::route('members.pages.index') }}" class="btn btn-large">Cancel</a>
        </div>

    {{ Form::close() }}
 
@stop
