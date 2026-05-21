<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\SanitizesLogData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PixianClient
{
    use SanitizesLogData;

    private string $baseUrl;

    private string $apiId;

    private string $apiSecret;

    private int $timeoutSeconds;

    private bool $testMode;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.pixian.base_url', 'https://api.pixian.ai/api/v2'), '/');
        $this->apiId = (string) config('services.pixian.api_id', '');
        $this->apiSecret = (string) config('services.pixian.api_secret', '');
        $this->timeoutSeconds = (int) config('services.pixian.timeout_seconds', 180);
        $this->testMode = (bool) config('services.pixian.test', false);
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.pixian.enabled', false)
            && $this->apiId !== ''
            && $this->apiSecret !== '';
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
            Log::warning('Pixian request skipped: image could not be read', [
                'image_path' => self::sanitizeForLog($imagePath),
            ]);

            return null;
        }

        try {
            $response = $this->makeRequest($imagePath, $imageContents);
        } catch (ConnectionException $e) {
            Log::warning('Pixian connection error during background removal', [
                'image_path' => self::sanitizeForLog($imagePath),
                'error' => self::sanitizeForLog($e->getMessage()),
            ]);

            return null;
        }

        if (! $response->successful()) {
            $this->logFailure($response, $imagePath);

            return null;
        }

        $body = $response->body();
        if ($body === '') {
            Log::warning('Pixian returned an empty response body', [
                'image_path' => self::sanitizeForLog($imagePath),
                'request_id' => self::sanitizeForLog((string) $response->header('x-request-id')),
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
        return Http::withBasicAuth($this->apiId, $this->apiSecret)
            ->timeout($this->timeoutSeconds)
            ->attach('image', $imageContents, basename($imagePath))
            ->post($this->baseUrl.'/remove-background', [
                'output.format' => 'png',
                'result.crop_to_foreground' => 'false',
                'test' => $this->testMode ? 'true' : 'false',
            ]);
    }

    private function logFailure(Response $response, string $imagePath): void
    {
        $body = $response->body();

        Log::warning('Pixian background removal failed', [
            'image_path' => self::sanitizeForLog($imagePath),
            'status' => $response->status(),
            'body' => self::sanitizeForLog(is_string($body) ? $body : (string) json_encode($response->json())),
            'request_id' => self::sanitizeForLog((string) $this->extractRequestId($response)),
        ]);
    }

    private function extractRequestId(Response $response): ?string
    {
        return $response->header('x-request-id') ?: null;
    }
}
