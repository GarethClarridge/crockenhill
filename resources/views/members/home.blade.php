@extends('page')

@section('dynamic_content')

<p>Welcome to the members area of the website.
  If you have any problems, talk to Gareth.
  Your account will need to be set up to access some features.</p>
<a href="/sermons" class="btn btn-default btn-lg btn-block">Edit sermons</a>
<a href="/members/songs" class="btn btn-default btn-lg btn-block">View song list</a>


<form action="logout" method="post">
  <br>
  {{ csrf_field() }}
 <button type="submit" name="logout" class="btn btn-primary btn-lg btn-block" role="button">Log out</button>
</form>

@stop
