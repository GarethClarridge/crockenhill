@extends('layouts.main')

@section('content')
<x-auth.shell heading="Login" description="Login to your account">
    <livewire:auth.login />
</x-auth.shell>
@endsection
