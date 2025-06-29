@extends('layouts.page')

@section('title', 'Register')
@section('description', 'Create a new account')
@section('heading', 'Register')

@section('dynamic_content')
    <livewire:auth.register />
@endsection 