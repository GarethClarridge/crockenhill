@extends('layouts.main')

@section('content')
<x-auth.shell heading="Login" description="Log in to your account">
    <livewire:auth.login />
</x-auth.shell>
@endsection
