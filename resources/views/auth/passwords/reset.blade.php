@extends('layouts/page')

@section('dynamic_content')

	<div>
		@if (count($errors) > 0)
			<div class="relative px-3 py-3 mb-4 border rounded bg-red-200 border-red-300 text-red-800">
				<strong>Whoops!</strong> There were some problems with your input.<br><br>
				<ul>
					@foreach ($errors->all() as $error)
						<li>{{ $error }}</li>
					@endforeach
				</ul>
			</div>
		@endif

		<form class="form-horizontal" role="form" method="POST" action="{{ url('/password/reset') }}">
			<input type="hidden" name="_token" value="{{ csrf_token() }}">
			<input type="hidden" name="token" value="{{ $token }}">

			<div class="mb-3">
				<label class="md:w-1/3 pr-4 pl-4 control-label">E-Mail Address</label>
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
				<label class="md:w-1/3 pr-4 pl-4 control-label">Confirm Password</label>
				<div class="md:w-1/2 pr-4 pl-4">
					<input type="password" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" name="password_confirmation">
				</div>
			</div>

			<div class="mb-3">
				<div class="md:w-1/2 pr-4 pl-4 col-md-offset-4">
					<button type="submit" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-blue-600 hover:bg-blue-600">
						Reset Password
					</button>
				</div>
			</div>
		</form>
	</div>
@endsection
