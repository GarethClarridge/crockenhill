# Frontend Polling Fix for Livestream Processing UI

## Problem
The Livewire MediaUpload component gets stuck at "5% - Preparing for processing..." because it has no status polling mechanism to update the UI when backend processing completes or fails.

## Root Cause
- Livewire component hardcodes initial values: `$currentStep = 'Preparing for processing...'; $progressPercentage = 5;`
- No `wire:poll` or JavaScript polling to check status updates
- Backend API works correctly (returns proper failed status with 0% progress)
- Frontend never learns about status changes

## Fix Implementation

### 1. Add Status Polling Method to MediaUpload.php

```php
public function checkProcessingStatus(): void
{
    if (!$this->processingId || $this->status === 'completed' || $this->status === 'failed') {
        return;
    }

    try {
        // Call the status API endpoint
        $response = Http::withToken(auth()->user()->createToken('polling')->plainTextToken)
            ->get("/api/sermons/processing/{$this->processingId}/status");

        if ($response->successful()) {
            $data = $response->json();

            if ($data['found']) {
                $this->status = $data['status'];
                $this->currentStep = $data['current_step'] ?? $this->currentStep;
                $this->progressPercentage = $data['progress_percentage'] ?? $this->progressPercentage;

                if ($data['status'] === 'failed') {
                    $this->errorMessage = $data['error_message'] ?? 'Processing failed';
                    $this->currentStep = 'Processing failed';
                    $this->progressPercentage = 0;
                } elseif ($data['status'] === 'completed') {
                    $this->successMessage = 'Processing completed successfully!';
                    $this->currentStep = 'Processing completed!';
                    $this->progressPercentage = 100;
                }
            }
        }
    } catch (\Exception $e) {
        Log::error('Failed to check processing status', [
            'processing_id' => $this->processingId,
            'error' => $e->getMessage()
        ]);
    }
}
```

### 2. Add Polling to Blade Template

Update `resources/views/livewire/media-upload.blade.php`:

```blade
{{-- Processing Status --}}
@if($showProcessingStatus)
    <div class="bg-white rounded-lg shadow-md p-6"
         wire:poll.2s="checkProcessingStatus">
        {{-- existing content --}}
    </div>
@endif
```

### 3. Improve Error State Display

```blade
{{-- Error State --}}
@if($status === 'failed')
    <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-md">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-red-800">Processing Failed</h3>
                <div class="mt-2 text-sm text-red-700">
                    <p>{{ $errorMessage }}</p>
                </div>
                <div class="mt-4">
                    <button wire:click="resetProcessingState"
                            class="bg-red-100 px-3 py-2 rounded-md text-sm font-medium text-red-800 hover:bg-red-200">
                        Try Again
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
```

### 4. Add Required Imports

Add to top of MediaUpload.php:
```php
use Illuminate\Support\Facades\Http;
```

## Testing Plan

1. Upload a livestream file that will fail
2. Verify UI updates to show "Processing failed" within 2 seconds
3. Verify error message is displayed
4. Verify "Try Again" button resets the form
5. Test with successful processing to ensure polling stops correctly

## Benefits

- Immediate user feedback (2-second polling)
- Clear error states with actionable buttons
- No more "stuck at 5%" issues
- Better user experience with proper status updates