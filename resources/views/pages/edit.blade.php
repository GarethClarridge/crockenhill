@extends('page', [
    'heading' => 'Edit page',
    'description' => '<meta name="description" content="Edit this page">'
])

@section('content')
<div class="container-fluid">
  <div class="row">
    <div class="col-md-8 col-md-offset-2">
    <br><br><br>
      <article class="card">
        <div class="header-container">
          <h1>Edit "{{ $page->heading }}"</h1>
        </div>
        <div>
          @if (count($errors) > 0)
            <div class="alert alert-danger">
              <strong>Whoops!</strong> There were some problems:<br><br>
              <ul>
                @foreach ($errors->all() as $error)
                  <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          @endif

          {{-- A form to edit the page. --}}

          {!! Form::model($page, array('method' => 'put', 'route' => array('members.pages.update', $page->slug), 'role' => 'form')) !!}

            <div class="form-group">
              {!! Form::label('heading', 'Heading') !!}
              {!! Form::text('heading', $value = null, array('class' => 'form-control')) !!}
            </div>

            <div class="form-group">
              {!! Form::label('description', 'Description') !!}
              {!! Form::text('description', $value = null, array('class' => 'form-control')) !!}
            </div>

            <div class="form-group">
              {!! Form::label('area', 'Website Area') !!}
              {!! Form::select('area', array(
                  'about-us'      => 'About Us', 
                  'whats-on'      => 'What\'s On', 
                  'find-us'       => 'Find Us', 
                  'contact-us'    => 'Contact Us',
                  'sermons'       => 'Sermons',
                  'members'       => 'Members'
                  ), $value = null, array('class' => 'form-control')) !!}
            </div>

            <div class="form-group">
              {!! Form::label('body', 'Content') !!}
              {!! Form::textarea('body', $value = null, array('class' => 'form-control')) !!}
            </div>
            
            <div class="form-actions">
              {!! Form::submit('Save', array('class' => 'btn btn-success btn-save btn-large')) !!}
              <a href="{{ URL::route('members.pages.index') }}" class="btn btn-large">Cancel</a>
            </div>

          {!! Form::close() !!}
        </div>
      </article>
      <br><br>
    </div>
  </div>
</div>
 
@stop
