@extends('layouts/page')

@section('dynamic_content')
	<div>
    @if (session('status'))
      <div class="relative px-3 py-3 mb-4 border rounded bg-green-200 border-green-300 text-green-800">
        {{ session('status') }}
      </div>
    @endif

    <form class="form-horizontal" role="form" method="POST" action="{{ url('/password/email') }}">
      <input type="hidden" name="_token" value="{{ csrf_token() }}">

      <div class="mb-3{{ $errors->has('email') ? ' has-error' : '' }}">
        <label class="md:w-1/3 pr-4 pl-4 control-label">Email Address</label>

        <div class="md:w-1/2 pr-4 pl-4">
          <input type="email" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" name="email" value="{{ old('email') }}">

          @if ($errors->has('email'))
            <span class="help-block">
              <strong>{{ $errors->first('email') }}</strong>
            </span>
          @endif
        </div>
      </div>

      <div class="mb-3">
        <div class="md:w-1/2 pr-4 pl-4 col-md-offset-4">
          <button type="submit" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-blue-600 hover:bg-blue-600">
            Send password reset link
          </button>
        </div>
      </div>
    </form>
	</div>
@endsection
