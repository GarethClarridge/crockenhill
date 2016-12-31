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
          <h1>Change heading image for "{{ $page->heading }}"</h1>
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

          {!! Form::open(array('action' => array('AdminPagesController@updateimage', $page->slug), 'files' => true)) !!}

            <div class="form-group">
              <label for="image">Image</label>
              {!! Form::file('image') !!}
            </div>

            <div class="form-actions">
              <input class="btn btn-success btn-lg" type="submit" value="Save">
              <a href="members/pages/" class="btn btn-large">Cancel</a>
            </div>

          </form>

        </div>
      </article>
      <br><br>
    </div>
  </div>
</div>

@stop
