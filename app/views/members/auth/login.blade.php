@extends('layouts.main')

@section('title', 'Members Area')

@section('description', '<meta name="description" content="This is the private members\' section of the website.">')

@section('content')

<main class="container">
    <div class="row">
        <div class="col-md-9">
            <article class="card">
                <div class="header-container">
                    <h1>Log In</h1>
                </div>
                <ol class="breadcrumb">
                    <li>{{ link_to_route('Home', 'Home') }}</li>
                    <li class="active">Log In</li>
                </ol>
                
                <p>This is the private area of the website for members. 
                If you're a member, please log in. 
                <br>
                
                {{ Form::open(array(
                    'class'             => 'form-horizontal', 
                    'role'              => 'form',
                    'method'            => 'POST',
                    'action'            => 'MemberController@doLogin',
                    'accept-charset'    => 'UTF-8'
                )) }}

                    <input type="hidden" name="_token" value="{{{ Session::getToken() }}}">

                    @if ($errors->has('login'))
                        <div class="alert alert-warning">{{ $errors->first('login', ':message') }}</div>
                    @endif

                    <div class="form-group">
                        {{ Form::label('email', 'Email', array('class' => 'col-sm-2 control-label')) }}
                        <div class="col-sm-10">
                             <input class="form-control" tabindex="1" placeholder="Email" type="text" name="email" id="email" value="{{{ Input::old('email') }}}">
                        </div>
                    </div>

                    <div class="form-group">
                        {{ Form::label('password', 'Password', array('class' => 'col-sm-2 control-label')) }}
                        <div class="col-sm-10">
                            <input class="form-control" tabindex="2" placeholder="{{{ Lang::get('confide::confide.password') }}}" type="password" name="password" id="password">                        </div>
                    </div>
                    
<!--                     <div class="checkbox">
    <label for="remember">
        <input type="hidden" name="remember" value="0">
        <input tabindex="4" type="checkbox" name="remember" id="remember" value="1"> {{{ Lang::get('confide::confide.login.remember') }}}
    </label>
</div> -->
                    @if (Session::get('error'))
                        <div class="alert alert-error alert-danger">{{{ Session::get('error') }}}</div>
                    @endif

                    @if (Session::get('notice'))
                        <div class="alert">{{{ Session::get('notice') }}}</div>
                    @endif

                    <div class="form-group">
                        <div class="col-sm-offset-2 col-sm-10"> 
                            <button tabindex="3" type="submit" class="btn btn-primary">{{{ Lang::get('confide::confide.login.submit') }}}</button>
                        </div>
                    </div>

                {{ Form::close() }}
                               
            </article>
        </div>
    </div>
    @yield('aside')

</main>

@stop
