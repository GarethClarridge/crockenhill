@extends('layouts.main')

@section('title', 'Create a New Member')

@section('description', '<meta name="description" content="This is the private members\' section of the website.">')

@section('content')

<main class="container">
    <div class="row">
        <div class="col-md-9">
            <article class="card">
                <div class="header-container">
                    <h1>Register for a member account</h1>
                </div>
                <ol class="breadcrumb">
                    <li>{{ link_to_route('Home', 'Home') }}</li>
                    <li class="active">Log In</li>
                </ol>
                
                <p>Use this form to create a new member account.</p>

                <form method="POST" action="{{{ URL::to('users') }}}" accept-charset="UTF-8">
                    <input type="hidden" name="_token" value="{{{ Session::getToken() }}}">

                    <fieldset>
                        <div class="form-group">
                            {{ Form::label('email', 'Email', array('class' => 'control-label')) }}
                            <input class="form-control" type="text" name="email" id="email" value="{{{ Input::old('email') }}}">
                        </div>
                        <div class="form-group">
                            {{ Form::label('password', 'Password', array('class' => 'control-label')) }}
                            <input class="form-control" type="password" name="password" id="password">
                        </div>
                        <div class="form-group">
                            {{ Form::label('password_confirmation', 'Password Confirmation', array('class' => 'control-label')) }}
                            <input class="form-control" type="password" name="password_confirmation" id="password_confirmation">
                        </div>

                        @if (Session::get('error'))
                            <div class="alert alert-error alert-danger">{{ var_dump(Session::get('error')) }}</div>
                        @endif

                        @if (Session::get('notice'))
                            <div class="alert">{{ var_dump(Session::get('notice')) }}</div>
                        @endif
                        
                        <br/>
                        <div class="form-actions form-group">
                          <button type="submit" class="btn btn-primary col-sm-12">{{{ Lang::get('confide::confide.signup.submit') }}}</button>
                        </div>

                    </fieldset>
                </form>
                            </article>
        </div>
    </div>
    @yield('aside')

</main>

@stop
