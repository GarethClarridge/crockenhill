<?php

namespace App\HealthChecks;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIHealthCheck implements Arrayable
{
    /**
     * The name of the health check.
     */
    public function name(): string
    {
        return 'openai-api';
    }

    /**
     * Run the health check.
     */
    public function run(): array
    {
        try {
            $apiKey = config('openai.api_key');

            if (empty($apiKey)) {
                return [
                    'status' => 'error',
                    'message' => 'OpenAI API key not configured',
                    'timestamp' => now()->toISOString(),
                ];
            }

            // Test OpenAI API connectivity with a minimal request
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(10)->get('https://api.openai.com/v1/models');

            if ($response->successful()) {
                return [
                    'status' => 'healthy',
                    'message' => 'OpenAI API is accessible',
                    'response_time' => $response->transferStats?->getTransferTime() ?? null,
                    'timestamp' => now()->toISOString(),
                ];
            }

            return [
                'status' => 'degraded',
                'message' => 'OpenAI API returned error: '.$response->status(),
                'response_code' => $response->status(),
                'timestamp' => now()->toISOString(),
            ];
        } catch (\Exception $e) {
            Log::warning('OpenAI health check failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to connect to OpenAI API: '.$e->getMessage(),
                'timestamp' => now()->toISOString(),
            ];
        }
    }

    /**
     * Convert the health check to an array.
     */
    public function toArray(): array
    {
        return $this->run();
    }
}
