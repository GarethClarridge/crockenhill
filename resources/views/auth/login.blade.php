@extends('layouts.page')

@section('title', 'Login')
@section('description', 'Login to your account')
@section('heading', 'Login')

@section('dynamic_content')
    <livewire:auth.login />
@endsection
