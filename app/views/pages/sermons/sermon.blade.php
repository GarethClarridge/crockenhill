@extends('pages.page')

@section('dynamic_content')

<audio src="/media/sermons/{{$sermon->filename}}.mp3" controls>
  Your browser does not support the <code>audio</code> element.
</audio>

<p>
  <span class="glyphicon glyphicon-calendar"></span>
  &nbsp; {{date ('jS \of F', strtotime($sermon->date))}}
</p>
<p>
  <span class="glyphicon glyphicon-user"></span> &nbsp
  {{ $sermon->preacher }}
</p>
<p>
  <span class="glyphicon glyphicon-book"></span> &nbsp
  {{ $sermon->reference }}
</p>

<p>
  <span class="glyphicon glyphicon-download-alt"></span> &nbsp;
  <a href="/media/sermons/{{$sermon->filename}}.mp3" download>
    Download this sermon to your computer.
  </a>
</p>

@stop