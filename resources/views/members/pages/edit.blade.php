@extends('layouts.members')

@section('title', 'Edit this Page')

@section('description', '<meta name="description" content="Edit this page">')

@section('membercontent')

    {{-- A notification system to show what's happened when edits are submitted. --}}
 
    {{ Notification::showAll() }}
     
    @if ($errors->any())
            <div class="alert alert-error">
                    {{ implode('<br>', $errors->all()) }}
            </div>
    @endif

    {{-- A form to edit the page. --}}
 
    {{ Form::model($page, array('method' => 'put', 'route' => array('members.pages.update', $page->slug), 'role' => 'form')) }}

        <div class="form-group">
            {{ Form::label('heading', 'Heading') }}
            {{ Form::text('heading', $value = null, array('class' => 'form-control')) }}
        </div>

        <div class="form-group">
            {{ Form::label('description', 'Description') }}
            {{ Form::text('description', $value = null, array('class' => 'form-control')) }}
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
            {{ Form::label('body', 'Content') }}
            {{ Form::textarea('body', $value = null, array('class' => 'form-control')) }}
        </div>
        
        <div class="form-actions">
            {{ Form::submit('Save', array('class' => 'btn btn-success btn-save btn-large')) }}
            <a href="{{ URL::route('members.pages.index') }}" class="btn btn-large">Cancel</a>
        </div>

    {{ Form::close() }}
 
@stop
