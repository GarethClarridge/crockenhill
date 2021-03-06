@extends('page',
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
							<strong>Whoops!</strong> There were some problems:<br><br>
							<ul>
								@foreach ($errors->all() as $error)
									<li>{{ $error }}</li>
								@endforeach
							</ul>
						</div>
					@endif

					<form class="form-horizontal" role="form" method="POST" action="{{ url('/auth/login') }}">
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
							<div class="col-md-6 col-md-offset-4">
								<input type="submit" class="btn btn-primary" value="Login"></input>

								<a class="btn btn-link" href="{{ url('/password/reset') }}">Forgot Your Password?</a>
							</div>
						</div>
					</form>
					<br>
					<p>
						If you haven't got an account you can <a href="{{ url('/register') }}">register here</a>.
						Use your email address from the church members' contact list to get access to members' content.
					</p>
				</div>
			</article>
			<br><br>
		</div>
	</div>
</div>
@endsection
