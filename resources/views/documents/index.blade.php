@extends('layouts/page')

@section('dynamic_content')

<div class="document-container">
  <div class="document-sub-container">
    <div class="row">
      <br>
      @foreach ($documents as $document)
        <div class="col-xs-6 col-sm-3 col-md-2">
          <a href="/media/documents/{{$document->filename}}" class="file">
            <div class="document-icon">
              <i class="far fa-file"></i>
            </div>
            <h4>
              <small>{{$document->title}}</small>
              <br><br>
            </h4>
          </a>

        </div>
      @endforeach
    </div>
  </div>
</div>

<div class="d-grid gap-2 m-3">
  <a href="/church/members/documents/create" class="btn btn-primary btn-lg" role="button">Upload a new document</a>
</div>

@stop
