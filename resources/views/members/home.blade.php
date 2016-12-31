@extends('page')

@section('dynamic_content')

<p>Welcome to the members area of the website.
  If you have any problems, talk to Gareth.
  Your account will need to be set up to access some features.</p>
<a href="/sermons" class="btn btn-default btn-lg btn-block">Edit sermons</a>
<a href="/members/songs" class="btn btn-default btn-lg btn-block">View song list</a>
<a href="/members/pages" class="btn btn-default btn-lg btn-block">Edit pages</a>
<a href="/members/documents" class="btn btn-default btn-lg btn-block">View documents</a>

<form action="logout" method="post">
  <br>
  <input type="hidden" name="_token" value="{{ csrf_token() }}">
 <button type="submit" name="logout" class="btn btn-primary btn-lg btn-block" role="button">Log out</button>
</form>

@stop
