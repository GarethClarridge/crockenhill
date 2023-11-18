@extends('layouts/page')

@section('dynamic_content')

  @if (session('message'))
    <div class="relative px-3 py-3 mb-4 border rounded bg-green-200 border-green-300 text-green-800 alert-dismissable opacity-0 opacity-100 block" role="alert">
      <i class="far fa-check-circle"></i> &nbsp
      {{ session('message') }}
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close">
      </button>
    </div>
  @endif

  <div class="main-content">

		<form class="form-horizontal" role="form" method="POST" action="{{ url('/login') }}">
			<input type="hidden" name="_token" value="{{ csrf_token() }}">

			@if (count($errors) > 0)
				<div class="relative px-3 py-3 mb-4 border rounded bg-red-200 border-red-300 text-red-800" role="alert">
					You have entered an invalid username or password.
				</div>
			@endif

			<div class="mb-3">
				<label class="md:w-1/3 pr-4 pl-4 control-label">Email Address</label>
				<div class="md:w-1/2 pr-4 pl-4">
					<input type="email" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" name="email" value="{{ old('email') }}">
				</div>
			</div>

			<div class="mb-3">
				<label class="md:w-1/3 pr-4 pl-4 control-label">Password</label>
				<div class="md:w-1/2 pr-4 pl-4">
					<input type="password" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" name="password">
				</div>
			</div>

			<div class="mb-3">
		    <div class="col-md-offset-4 md:w-1/2 pr-4 pl-4">
		      <div class="checkbox">
		        <label>
		          <input type="checkbox" checked="checked" name="remember"> Remember me
		        </label>
		      </div>
		    </div>
		  </div>

			<div class="mb-3">
				<div class="md:w-1/2 pr-4 pl-4 col-md-offset-4">
					<input type="submit" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-blue-600 hover:bg-blue-600" value="Log in"></input>
					<a class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline font-normal text-blue-700 bg-transparent" href="{{ url('/password/reset') }}">Forgot Your Password?</a>
					<br>
					<br>
					<br>
					<a class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-blue-600 hover:bg-blue-600" href="{{ url('/register') }}">Register for an account</a>
				</div>
			</div>

		</form>
	</div>
@endsection
