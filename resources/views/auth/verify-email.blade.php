@extends('layouts.page')

@section('title', 'Verify Email')
@section('description', 'Verify your email address to continue')
@section('heading', 'Verify Email')

@section('dynamic_content')
    <livewire:auth.verify-email />
@endsection 