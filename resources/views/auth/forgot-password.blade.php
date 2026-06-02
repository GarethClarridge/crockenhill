@extends('layouts.main')

@section('content')
<x-auth.shell heading="Forgot password" description="Reset your password">
    <livewire:auth.forgot-password />
</x-auth.shell>
@endsection
