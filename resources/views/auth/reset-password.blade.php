@extends('layouts.main')

@section('content')
<x-auth.shell heading="Reset Password" description="Set a new password for your account">
    <livewire:auth.reset-password :token="$token" />
</x-auth.shell>
@endsection
