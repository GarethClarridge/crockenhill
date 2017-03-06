@extends('page_empty',
	['heading' => 'Log in',
	'description' => '<meta name="description" content="Log in to the member area">']
	)

@section('content')
<div class="container-fluid">
	<div class="row">
		<div class="col-md-8 col-md-offset-2">
		<br><br><br>
			<article class="card">
        <div class="header-container">
            <h1><span>Log in</span></h1>
        </div>
				<div>
					@if (count($errors) > 0)
						<div class="alert alert-danger">
							You have entered an invalid username or password.
						</div>
					@endif

					<form class="form-horizontal" role="form" method="POST" action="{{ url('/login') }}">
						<input type="hidden" name="_token" value="{{ csrf_token() }}">

						<div class="form-group">
							<label class="col-md-4 control-label">Email Address</label>
							<div class="col-md-6">
								<input type="email" class="form-control" name="email" value="{{ old('email') }}">
							</div>
						</div>

						<div class="form-group">
							<label class="col-md-4 control-label">Password</label>
							<div class="col-md-6">
								<input type="password" class="form-control" name="password">
							</div>
						</div>

						<div class="form-group">
					    <div class="col-md-offset-4 col-md-6">
					      <div class="checkbox">
					        <label>
					          <input type="checkbox" checked="checked" name="remember"> Remember me
					        </label>
					      </div>
					    </div>
					  </div>

						<div class="form-group">
							<div class="col-md-6 col-md-offset-4">
								<input type="submit" class="btn btn-primary" value="Log in"></input>
								<a class="btn btn-link" href="{{ url('/password/reset') }}">Forgot Your Password?</a>
								<br>
								<br>
								<br>
								<a class="btn btn-default" href="{{ url('/register') }}">Register for an account</a>
							</div>
						</div>

					</form>
				</div>
			</article>
			<br><br>
		</div>
	</div>
</div>
@endsection
