@extends('layouts.members')

@section('title', 'Create a New Member')

@section('description', '<meta name="description" content="This is the private members\' section of the website.">')

@section('membercontent')
                
    <p>Use this form to create a new member account.</p>

    <form method="POST" action="{{{ URL::to('users') }}}" accept-charset="UTF-8">
        <input type="hidden" name="_token" value="{{{ Session::getToken() }}}">

        <fieldset>
            <div class="form-group">
                {{ Form::label('username', 'Name', array('class' => 'control-label')) }}
                <input class="form-control" type="text" name="username" id="username" value="{{{ Input::old('username') }}}">
            </div>
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
                <div class="alert alert-error alert-danger">{{{ Session::get('error') }}}</div>
            @endif

            @if (Session::get('notice'))
                <div class="alert">{{{ Session::get('notice') }}}</div>
            @endif
            
            <br/>
            <div class="form-actions form-group">
              <button type="submit" class="btn btn-primary col-sm-12">{{{ Lang::get('confide::confide.signup.submit') }}}</button>
            </div>

        </fieldset>
    </form>

@stop
