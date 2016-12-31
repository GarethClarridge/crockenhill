@extends('page', [
    'heading' => 'Upload a new document',
    'description' => '<meta name="description" content="Upload a new document">'
])

@section('content')
<div class="container-fluid">
  <div class="row">
    <div class="col-md-8 col-md-offset-2">
    <br><br><br>
      <article class="card">
        <div class="header-container">
          <h1>Upload a new document</h1>
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

					{!! Form::open(array('action' => 'DocumentController@store', 'method' => 'POST', 'files' => true)) !!}

						<div class="form-group">
							<label for="title">Title</label>
							<input class="form-control" id="title" name="title" type="text" value="{{$document->title}}"> 
						</div>

							<div class="form-group">
							  <label for="document">Document</label>
							  {!! Form::file('document') !!}
							</div>

						<div class="form-group">
							<label for="type">Document Type</label>
							{!! Form::select('type', array(
								''                  => 'Please select ...',
								'meeting'           => 'Church Meeting Information',
								'bible-study-notes' => 'Bible Study Notes',
								'rota'              => 'Rota',
								), $value = null, array('class' => 'form-control')) !!}
						</div>

						<div class="form-actions">
							<input class="btn btn-success btn-lg" type="submit" value="Save">
						</div>

					</form>
        </div>
      </article>
      <br><br>
    </div>
  </div>
</div>

@stop
