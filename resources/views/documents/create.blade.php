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

          <form method="POST" action="/members/documents" accept-charset="UTF-8" enctype="multipart/form-data" class="create">
            <input type="hidden" name="_token" id="csrf-token" value="{{ Session::token() }}" />

						<div class="form-group">
							<label for="title">Title</label>
							<input class="form-control" id="title" name="title" type="text">
						</div>

							<div class="form-group">
							  <label for="document">Document</label>
                <input type="file" name="document">
							</div>

						<div class="form-group">
							<label for="type">Document Type</label>
              <select class="form-control" name="type">
                <option value="">Please select</option>
                <option value="meeting">Church meeting documents</option>
                <option value="bible-study-notes">Bible study notes</option>
                <option value="rota">Rota</option>
              </select>
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
