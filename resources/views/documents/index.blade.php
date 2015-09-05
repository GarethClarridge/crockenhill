@extends('page')

@section('dynamic_content')

<div class="document-container">
  <div class="document-sub-container">
    <div class="row">
      <br>
      @foreach ($documents as $document)
        <div class="col-xs-6 col-sm-3 col-md-2">
          <a href="/media/documents/{{$document->filename}}" class="file">
            <div class="document-icon">
              <span class="glyphicon glyphicon-file" aria-hidden="true"></span>
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

<br>
<a href="/members/document/create" class="btn btn-primary btn-lg btn-block" role="button">Upload a new document</a>

@stop