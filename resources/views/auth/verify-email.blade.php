@extends('layouts.main')

@section('content')
<x-auth.shell heading="Verify Email" description="Verify your email address to continue">
    <livewire:auth.verify-email />
</x-auth.shell>
@endsection
