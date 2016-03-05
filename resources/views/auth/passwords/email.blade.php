@extends('page',
	['heading' => 'Forgotten password',
	'description' => '<meta name="description" content="Forgotten password">']
	)

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <br><br><br>
      			<article class="card">
              <div class="header-container">
                  <h1><span>Forgotten password</span></h1>
              </div>
      				<div>
                    @if (session('status'))
                        <div class="alert alert-success">
                            {{ session('status') }}
                        </div>
                    @endif

                    <form class="form-horizontal" role="form" method="POST" action="{{ url('/password/email') }}">
                        {!! csrf_field() !!}

                        <div class="form-group{{ $errors->has('email') ? ' has-error' : '' }}">
                            <label class="col-md-4 control-label">Email Address</label>

                            <div class="col-md-6">
                                <input type="email" class="form-control" name="email" value="{{ old('email') }}">

                                @if ($errors->has('email'))
                                    <span class="help-block">
                                        <strong>{{ $errors->first('email') }}</strong>
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="col-md-6 col-md-offset-4">
                                <button type="submit" class="btn btn-primary">
                                    Send password reset link
                                </button>
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
