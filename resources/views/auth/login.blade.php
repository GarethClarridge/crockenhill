@extends('layouts/page')

@section('dynamic_content')

  @if (session('message'))
    <div class="alert alert-success alert-dismissable" role="alert">
      <button type="button" class="close" data-dismiss="alert" aria-label="Close">
        <span aria-hidden="true">&times;</span>
      </button>
      <i class="far fa-check-circle"></i> &nbsp
      {{ session('message') }}
    </div>
  @endif

  <div class="main-content">

		<form class="form-horizontal" role="form" method="POST" action="{{ url('/login') }}">
			<input type="hidden" name="_token" value="{{ csrf_token() }}">

			@if (count($errors) > 0)
				<div class="alert alert-danger" role="alert">
					You have entered an invalid username or password.
				</div>
			@endif

			<div class="mb-3">
				<label class="col-md-4 control-label">Email Address</label>
				<div class="col-md-6">
					<input type="email" class="form-control" name="email" value="{{ old('email') }}">
				</div>
			</div>

			<div class="mb-3">
				<label class="col-md-4 control-label">Password</label>
				<div class="col-md-6">
					<input type="password" class="form-control" name="password">
				</div>
			</div>

			<div class="mb-3">
		    <div class="col-md-offset-4 col-md-6">
		      <div class="checkbox">
		        <label>
		          <input type="checkbox" checked="checked" name="remember"> Remember me
		        </label>
		      </div>
		    </div>
		  </div>

			<div class="mb-3">
				<div class="col-md-6 col-md-offset-4">
					<input type="submit" class="btn btn-primary" value="Log in"></input>
					<a class="btn btn-link" href="{{ url('/password/reset') }}">Forgot Your Password?</a>
					<br>
					<br>
					<br>
					<a class="btn btn-primary" href="{{ url('/register') }}">Register for an account</a>
				</div>
			</div>

		</form>
	</div>
@endsection
