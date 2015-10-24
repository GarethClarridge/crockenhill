<h3>
  <a href="sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">{{$sermon->title}}</a>
</h3> 
<p>
  <span class="glyphicon glyphicon-calendar"></span>
  &nbsp; {{date ('jS \of F Y', strtotime($sermon->date))}}
</p>
<p>
  <span class="glyphicon glyphicon-user"></span> &nbsp
  <a href="/sermons/preachers/{{ \Illuminate\Support\Str::slug($sermon->preacher) }}">{{ $sermon->preacher }}</a>
</p>
<p>
  <span class="glyphicon glyphicon-book"></span> &nbsp
  {{ $sermon->reference }}
</p>
@if ($user != null && $user->email == "admin@crockenhill.org")
  {!! Form::open(array('action' => array('SermonController@destroy', $sermon->slug), 'method' => 'delete')) !!}
    <div class="btn-group">
      <a href="/sermons/{{$sermon->slug}}/edit" class="btn btn-primary">
        Edit this sermon
      </a>
      
      <button type="submit" class="btn btn-danger">
        Delete this sermon
      </button>
    </div>
  {!! Form::close() !!}
@endif