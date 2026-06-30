<div class="bg-white rounded-lg shadow-sm border">
    {{-- Header with skeleton loader --}}
    <div class="flex items-center justify-between p-4 border-b">
        <div class="flex items-center space-x-3">
            <div class="h-6 w-32 bg-gray-200 rounded animate-pulse"></div>
            <div class="h-5 w-16 bg-gray-200 rounded-full animate-pulse"></div>
        </div>

        <div class="flex items-center space-x-2">
            <div class="h-5 w-24 bg-gray-200 rounded animate-pulse"></div>
            <div class="h-6 w-6 bg-gray-200 rounded animate-pulse"></div>
            <div class="h-6 w-20 bg-gray-200 rounded animate-pulse"></div>
        </div>
    </div>

    {{-- Loading content --}}
    <div class="p-6 text-center">
        <div class="flex flex-col items-center justify-center space-y-3">
            {{-- Spinner --}}
            <svg class="animate-spin h-8 w-8 text-cbc-teal" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>

            {{-- Loading text --}}
            <p class="text-sm text-gray-600">Loading processing logs...</p>
        </div>
    </div>
</div>
