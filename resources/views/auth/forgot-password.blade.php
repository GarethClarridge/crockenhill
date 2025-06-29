@extends('layouts.page')

@section('title', 'Forgot Password')
@section('description', 'Reset your password')
@section('heading', 'Forgot Password')

@section('dynamic_content')
    <livewire:auth.forgot-password />
@endsection 