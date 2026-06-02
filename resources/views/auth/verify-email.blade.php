@extends('layouts.main')

@section('content')
<x-auth.shell heading="Verify email" description="Verify your email address to continue">
    <livewire:auth.verify-email />
</x-auth.shell>
@endsection
