@extends('layouts.members')

@section('title', 'Change the Heading Image')

@section('description', '<meta name="description" content="Change the Heading Image">')

@section('membercontent')

  {{-- A notification system to show what's happened when edits are submitted. --}}

  {{ Notification::showAll() }}
   
  @if ($errors->any())
    <div class="alert alert-error">
      {{ implode('<br>', $errors->all()) }}
    </div>
  @endif

  {{ Form::open(array('action' => array('AdminPagesController@updateimage', $page->slug), 'files' => true)) }}

    <div class="form-group">
      {{ Form::label('image', 'Image') }}
      {{ Form::file('image') }}
    </div>

    <div class="form-actions">
      {{ Form::submit('Save', array('class' => 'btn btn-success btn-save btn-large')) }}
      <a href="{{ URL::route('members.pages.index') }}" class="btn btn-large">Cancel</a>
    </div>

  {{ Form::close() }}
 
@stop
