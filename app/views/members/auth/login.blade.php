@extends('layouts.main')

@section('title', 'Members Area')

@section('description', '<meta name="description" content="This is the private members\' section of the website.">')

@section('content')

<main class="container">
    <div class="row">
        <div class="col-md-9">
            <article class="card">
                <div class="header-container"">
                    <h1>Log In</h1>
                </div>
                <ol class="breadcrumb">
                    <li>{{ link_to_route('Home', 'Home') }}</li>
                    <li class="active">Log In</li>
                </ol>
                
                <p>This is the private area of the website for members. If you're a member, please log in. If you're not a member - don't worry, you're not missing out on anything interesting.</p>
                <br>
                
                {{ Form::open(array('class' => 'form-horizontal', 'role' => 'form')) }}

                    @if ($errors->has('login'))
                        <div class="alert alert-warning">{{ $errors->first('login', ':message') }}</div>
                    @endif

                    <div class="form-group">
                        {{ Form::label('email', 'Email', array('class' => 'col-sm-2 control-label')) }}
                        <div class="col-sm-10">
                            {{ Form::text('email', $value = null, $attributes = array('class' => 'form-control')) }}
                        </div>
                    </div>

                    <div class="form-group">
                        {{ Form::label('password', 'Password', array('class' => 'col-sm-2 control-label')) }}
                        <div class="col-sm-10">
                            {{ Form::password('password', $attributes = array('class' => 'form-control')) }}
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="col-sm-offset-2 col-sm-10"> 
                            {{ Form::submit('Login', array('class' => 'btn btn-primary')) }}
                        </div>
                    </div>

                {{ Form::close() }}
                               
            </article>
        </div>
    </div>
    @yield('aside')

</main>

@stop
