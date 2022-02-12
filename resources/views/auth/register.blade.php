@extends('layouts/page')

@section('dynamic_content')

	<div>
		@if (count($errors) > 0)
			<div class="alert alert-danger">
				<strong>There were some problems with those details:</strong><br><br>
				<ul>
					@foreach ($errors->all() as $error)
						<li>{{ $error }}</li>
					@endforeach
				</ul>
			</div>
		@endif

		<form class="form-horizontal" role="form" method="POST" action="register">
			<input type="hidden" name="_token" value="{{ csrf_token() }}">

			<div class="mb-3">
				<label class="col-md-4 control-label">Name</label>
				<div class="col-md-6">
					<input type="text" class="form-control" name="name" value="{{ old('name') }}">
				</div>
			</div>

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
				<label class="col-md-4 control-label">Confirm Password</label>
				<div class="col-md-6">
					<input type="password" class="form-control" name="password_confirmation">
				</div>
			</div>

			<div class="mb-3">
				<div class="col-md-6 col-md-offset-4">
					<div class="d-grid gap-2 mb-3">
						<button type="submit" class="btn btn-primary">
							Register
						</button>
					</div>
				</div>
			</div>
		</form>
	</div>
@endsection
