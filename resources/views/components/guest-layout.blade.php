<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'Laravel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-100 min-h-screen flex flex-col justify-center items-center">
    <main class="w-full max-w-lg mx-auto p-6">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html> 