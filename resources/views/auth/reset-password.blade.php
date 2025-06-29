@extends('layouts.page')

@section('title', 'Reset Password')
@section('description', 'Set a new password for your account')
@section('heading', 'Reset Password')

@section('dynamic_content')
    <livewire:auth.reset-password :token="$token" />
@endsection 