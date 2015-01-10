@extends('pages.page')

@section('social_sharing')

<!-- <ul class="social_sharing list-inline">
    <li>
        <a href="http://www.facebook.com/crockenhill">
            <i class="fa fa-facebook-square"></i>
        </a>
    </li>
    
    <li>
        <a href="http://www.twitter.com/crockenhill">
            <i class="fa fa-twitter-square"></i>
        </a>
    </li>
    
    <li>
        <a href="http://www.facebook.com/crockenhill">
            <i class="fa fa-google-plus-square"></i>
        </a>
    </li>
</ul> -->

@stop

@section('dynamic_content')

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

<audio src="/media/sermons/{{$sermon->filename}}.mp3" controls>
  Your browser does not support the <code>audio</code> element.
</audio>

<h2>Bible passage</h2>

@foreach ($passage as $part)
    {{ $part }}
@endforeach

<hr>

<p>If there are any problems with this sermon, please email 
    <a href="mailto:sermons@crockenhill.org">sermons@crockenhill.org</a>.
</p>

<small>
Scripture taken from The Holy Bible, English Standard Version. Copyright &copy;2001 by <a href="http://www.crosswaybibles.org">Crossway Bibles</a>, a publishing ministry of Good News Publishers. Used by permission. All rights reserved. Text provided by the <a href="http://www.gnpcb.org/esv/share/services/">Crossway Bibles Web Service</a>.
</small>

@stop
