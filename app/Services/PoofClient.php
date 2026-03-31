<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PoofClient
{
    private string $baseUrl;

    private string $apiKey;

    private int $timeoutSeconds;

    private int $maxRetries;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.poof.base_url', 'https://api.poof.bg/v1'), '/');
        $this->apiKey = (string) config('services.poof.api_key', '');
        $this->timeoutSeconds = (int) config('services.poof.timeout_seconds', 15);
        $this->maxRetries = (int) config('services.poof.max_retries', 2);
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.poof.enabled', false) && $this->apiKey !== '';
    }

    /**
     * @return array{contents:string,request_id:?string}|null
     */
    public function removeBackground(string $imagePath): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $imageContents = @file_get_contents($imagePath);
        if (! is_string($imageContents) || $imageContents === '') {
            Log::warning('Poof request skipped: image could not be read', [
                'image_path' => $imagePath,
            ]);

            return null;
        }

        try {
            $response = $this->makeRequest($imagePath, $imageContents);
        } catch (ConnectionException $e) {
            Log::warning('Poof connection error during background removal', [
                'image_path' => $imagePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->logTerminalFailure($response, $imagePath);

            return null;
        }

        $body = $response->body();
        if ($body === '') {
            Log::warning('Poof returned an empty response body', [
                'image_path' => $imagePath,
                'request_id' => $response->header('x-request-id'),
            ]);

            return null;
        }

        return [
            'contents' => $body,
            'request_id' => $this->extractRequestId($response),
        ];
    }

    private function makeRequest(string $imagePath, string $imageContents): Response
    {
        $attempt = 0;
        $maxAttempts = max(1, $this->maxRetries + 1);

        do {
            $attempt++;

            try {
                $response = Http::withHeaders(['x-api-key' => $this->apiKey])
                    ->timeout($this->timeoutSeconds)
                    ->attach('image_file', $imageContents, basename($imagePath))
                    ->post($this->baseUrl.'/remove', [
                        'format' => 'webp',
                        'size' => 'full',
                        'crop' => 'false',
                    ]);
            } catch (ConnectionException $e) {
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }

                usleep(500000);

                continue;
            }

            if (! in_array($response->status(), [429, 502], true) || $attempt >= $maxAttempts) {
                return $response;
            }

            usleep(500000);
        } while (true);
    }

    private function logTerminalFailure(Response $response, string $imagePath): void
    {
        $status = $response->status();
        $code = is_string($response->json('code')) ? $response->json('code') : null;
        $message = is_string($response->json('message')) ? $response->json('message') : null;
        $requestId = $this->extractRequestId($response);

        Log::warning('Poof background removal failed', [
            'image_path' => $imagePath,
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ]);
    }

    private function extractRequestId(Response $response): ?string
    {
        $requestId = $response->header('x-request-id');
        if ($requestId !== '') {
            return $requestId;
        }

        $bodyRequestId = $response->json('request_id');

        return is_string($bodyRequestId) && $bodyRequestId !== '' ? $bodyRequestId : null;
    }
}
