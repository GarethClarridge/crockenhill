@extends('layouts.main')

@section('content')
<x-auth.shell heading="Log in" description="Log in to your account">
    <livewire:auth.login />
</x-auth.shell>
@endsection
