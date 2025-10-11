<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogLivewireUploads
{
    public function handle(Request $request, Closure $next)
    {
        // Only log Livewire upload requests
        if ($request->is('livewire/upload-file')) {
            Log::info('Livewire upload request received', [
                'content_length' => $request->header('Content-Length'),
                'content_type' => $request->header('Content-Type'),
                'user_agent' => $request->header('User-Agent'),
                'ip' => $request->ip(),
                'timestamp' => now()->toDateTimeString(),
            ]);

            $startTime = microtime(true);
        }

        $response = $next($request);

        // Log response for upload requests
        if ($request->is('livewire/upload-file')) {
            $duration = isset($startTime) ? round((microtime(true) - $startTime) * 1000, 2) : 0;
            
            Log::info('Livewire upload response', [
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
                'response_size' => strlen($response->getContent()),
            ]);

            // Log errors in detail
            if ($response->getStatusCode() !== 200) {
                Log::error('Livewire upload failed', [
                    'status' => $response->getStatusCode(),
                    'response' => $response->getContent(),
                    'duration_ms' => $duration,
                ]);
            }
        }

        return $response;
    }
}
