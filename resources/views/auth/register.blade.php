@extends('layouts.main')

@section('content')
<x-auth.shell heading="Register" description="Create a new account">
    <livewire:auth.register />
</x-auth.shell>
@endsection
