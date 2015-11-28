@extends('page')

@section('dynamic_content')

  <form method="POST" action="/members/pages" accept-charset="UTF-8" enctype="multipart/form-data" class="create">

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
