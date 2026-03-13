<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ApiBiblePassageResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiBibleClient
{
    private string $baseUrl;

    private string $apiKey;

    private string $bibleId;

    private string $fumsVersion;

    private int $timeoutSeconds;

    private int $maxRetries;

    public function __construct()
    {
        $this->baseUrl = (string) config('services.api_bible.base_url', 'https://api.scripture.api.bible/v1');
        $this->apiKey = (string) config('services.api_bible.key', '');
        $this->bibleId = (string) config('services.api_bible.default_bible_id', '');
        $this->fumsVersion = (string) config('services.api_bible.fums_version', '3');
        $this->timeoutSeconds = (int) config('services.api_bible.timeout_seconds', 10);
        $this->maxRetries = (int) config('services.api_bible.max_retries', 3);
    }

    /**
     * Search for a passage by normalized reference text and return the resolved passage.
     * Uses a single search call which returns content in the response.
     * Returns null if not found or if the API call fails.
     */
    public function searchPassage(string $normalizedReference): ?ApiBiblePassageResult
    {
        try {
            $response = $this->makeRequest('get', "/bibles/{$this->bibleId}/search", [
                'query' => $normalizedReference,
                'fums-version' => $this->fumsVersion,
            ]);

            if (! $response->successful()) {
                Log::warning('api.bible search returned non-2xx', [
                    'reference' => $normalizedReference,
                    'status' => $response->status(),
                ]);

                return null;
            }

            /** @var array<string, mixed> $data */
            $data = $response->json('data', []);

            /** @var array<int, array<string, mixed>> $passages */
            $passages = is_array($data['passages'] ?? null) ? $data['passages'] : [];

            if (empty($passages)) {
                Log::info('api.bible search returned no passages', ['reference' => $normalizedReference]);

                return null;
            }

            $passage = $passages[0];

            /** @var string $passageId */
            $passageId = is_string($passage['id'] ?? null) ? $passage['id'] : '';
            /** @var string $displayRef */
            $displayRef = is_string($passage['reference'] ?? null) ? $passage['reference'] : $normalizedReference;
            /** @var string $htmlContent */
            $htmlContent = is_string($passage['content'] ?? null) ? $passage['content'] : '';
            /** @var string $copyright */
            $copyright = is_string($passage['copyright'] ?? null) ? $passage['copyright'] : '';

            if ($htmlContent === '' || $passageId === '') {
                return null;
            }

            /** @var array<string, mixed> $meta */
            $meta = is_array($response->json('meta')) ? $response->json('meta') : [];
            $fumsToken = is_string($meta['fumsToken'] ?? null) ? $meta['fumsToken'] : null;

            return new ApiBiblePassageResult(
                passageId: $passageId,
                displayReference: $displayRef,
                htmlContent: $htmlContent,
                copyright: $copyright,
                fumsToken: $fumsToken,
            );
        } catch (ConnectionException $e) {
            Log::error('api.bible connection error during search', [
                'reference' => $normalizedReference,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (RequestException $e) {
            Log::error('api.bible request error during search', [
                'reference' => $normalizedReference,
                'status' => $e->response->status(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Refresh an existing passage by its stored passage ID.
     * Used for scheduled cache refreshes to avoid re-searching by text.
     * Returns null if the passage is no longer available.
     */
    public function fetchPassageById(string $passageId): ?ApiBiblePassageResult
    {
        try {
            $response = $this->makeRequest('get', "/bibles/{$this->bibleId}/passages/{$passageId}", [
                'content-type' => 'html',
                'include-verse-numbers' => 'true',
                'fums-version' => $this->fumsVersion,
            ]);

            if (! $response->successful()) {
                Log::warning('api.bible passage fetch returned non-2xx', [
                    'passage_id' => $passageId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            /** @var array<string, mixed> $passageData */
            $passageData = is_array($response->json('data')) ? $response->json('data') : [];

            /** @var string $htmlContent */
            $htmlContent = is_string($passageData['content'] ?? null) ? $passageData['content'] : '';
            /** @var string $displayRef */
            $displayRef = is_string($passageData['reference'] ?? null) ? $passageData['reference'] : '';
            /** @var string $copyright */
            $copyright = is_string($passageData['copyright'] ?? null) ? $passageData['copyright'] : '';

            if ($htmlContent === '') {
                return null;
            }

            /** @var array<string, mixed> $meta */
            $meta = is_array($response->json('meta')) ? $response->json('meta') : [];
            $fumsToken = is_string($meta['fumsToken'] ?? null) ? $meta['fumsToken'] : null;

            return new ApiBiblePassageResult(
                passageId: $passageId,
                displayReference: $displayRef,
                htmlContent: $htmlContent,
                copyright: $copyright,
                fumsToken: $fumsToken,
            );
        } catch (ConnectionException $e) {
            Log::error('api.bible connection error during passage fetch', [
                'passage_id' => $passageId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (RequestException $e) {
            Log::error('api.bible request error during passage fetch', [
                'passage_id' => $passageId,
                'status' => $e->response->status(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function makeRequest(string $method, string $path, array $query = []): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders(['api-key' => $this->apiKey])
            ->timeout($this->timeoutSeconds)
            ->retry($this->maxRetries, 500, function (\Throwable $exception, \Illuminate\Http\Client\PendingRequest $request): bool {
                return $exception instanceof ConnectionException;
            })
            ->$method("{$this->baseUrl}{$path}", $query);
    }
}
