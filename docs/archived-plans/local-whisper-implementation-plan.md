# Local Whisper Implementation Plan

**Goal:** Avoid ~£1/transcription OpenAI API cost during local development by running Whisper locally.

**Date:** 2026-03-27

---

## Current Architecture

The transcription system is cleanly abstracted behind `TranscriptionServiceInterface`, with implementations swapped via `TRANSCRIPTION_SERVICE_TYPE` in `AiServiceProvider`:

| Value | Service | Use case |
|-------|---------|----------|
| `openai` | `AudioTranscriptionService` | Production — calls `gpt-4o-transcribe` via OpenAI API |
| `mock` | `MockTranscriptionService` | Testing — returns static transcript text |

The `AudioTranscriptionService` uses the `openai-php/laravel` facade (`OpenAI::audio()->transcribe()`), which is configured globally in `config/openai.php`. Overriding `OPENAI_BASE_URL` would redirect **all** OpenAI calls (sermon analysis, section classification, etc.), not just transcription — so we need a dedicated service.

---

## Recommended Approach

**Add a `faster-whisper-server` Docker container + new `LocalWhisperTranscriptionService`.**

This follows the existing pattern (interface + service provider routing) and keeps production code untouched.

### Why faster-whisper-server?

| Option | OpenAI-compatible API | Docker image | macOS GPU | Notes |
|--------|----------------------|--------------|-----------|-------|
| **faster-whisper-server** | Yes | `fedirz/faster-whisper-server` | No (CPU in Docker) | Best maintained, simplest Docker integration |
| whisper.cpp server | Yes | Official image available | Yes (native only) | Faster natively on Apple Silicon, but no advantage in Docker |
| LocalAI | Yes | `localai/localai` | No | Heavy, supports many model types beyond Whisper |

**Docker on macOS runs inside a Linux VM, so Metal/CoreML acceleration is unavailable.** All Docker-based solutions run CPU-only. `faster-whisper-server` with CTranslate2 is well-optimised for CPU inference and is the most practical choice for a Sail-based workflow.

For typical sermon audio (~30-60 minutes), expect:
- `small` model: ~6-10 minutes transcription time (good accuracy for clear English speech)
- `medium` model: ~15-30 minutes (better accuracy, especially for proper nouns)
- `turbo` (large-v3-turbo): ~20-40 minutes (near-production accuracy, heaviest on CPU)

**Recommendation:** Start with `small` — it handles single-speaker, clear-audio English sermons well and keeps iteration fast.

### Optional: Native whisper.cpp for faster runs

For users who want faster transcription on Apple Silicon, add an alternative path:

1. Install whisper.cpp natively (`brew install whisper-cpp` or build from source)
2. Run `whisper-server` outside Docker with Metal acceleration
3. Point `LOCAL_WHISPER_URL` at `http://host.docker.internal:<port>` from within Sail

This is ~3-5x faster than Docker CPU but requires manual setup. The implementation supports both — only the URL changes.

---

## Implementation Steps

### Step 1: Add Docker Service

Add to `docker-compose.yml`:

```yaml
whisper:
    image: fedirz/faster-whisper-server:latest-cpu
    environment:
        WHISPER__MODEL: small
        WHISPER__INFERENCE_DEVICE: cpu
    ports:
        - '${FORWARD_WHISPER_PORT:-8200}:8000'
    networks:
        - sail
    profiles:
        - whisper
```

**Key decisions:**
- **`profiles: [whisper]`** — The container won't start with `sail up` by default. Opt in with `sail up -d --profile whisper` or `docker compose --profile whisper up -d`. This avoids downloading a ~2GB model image for developers who don't need local transcription.
- **Port 8200** — Avoids conflicts with the app (80), Mailpit (8025), and Vite (5173).
- **`latest-cpu` tag** — Smaller image without CUDA dependencies.
- **Model downloaded on first start** — The `small` model (~460MB) is downloaded and cached in the container. Consider adding a named volume for model persistence if rebuilds are frequent.

### Step 2: Add Configuration

In `config/media-processing.php`, extend the `transcription` block:

```php
'transcription' => [
    'service' => env('TRANSCRIPTION_SERVICE_TYPE', 'mock'),
    'openai_api_key' => env('OPENAI_API_KEY'),
    'max_file_size' => 25 * 1024 * 1024,
    'timeout' => 300,
    'max_retries' => env('TRANSCRIPTION_MAX_RETRIES', 3),
    'retry_delay_base' => env('TRANSCRIPTION_RETRY_DELAY_BASE', 2),
    // Local Whisper settings
    'local_whisper_url' => env('LOCAL_WHISPER_URL', 'http://whisper:8000'),
    'local_whisper_model' => env('LOCAL_WHISPER_MODEL', 'small'),
    'local_whisper_timeout' => env('LOCAL_WHISPER_TIMEOUT', 600),
],
```

**Notes:**
- `local_whisper_url` defaults to the Docker service name (`whisper:8000`) — works within the Sail network. For native whisper.cpp, set to `http://host.docker.internal:<port>`.
- `local_whisper_timeout` is 600s (10 min) vs 300s for OpenAI, because local CPU transcription is slower.
- `local_whisper_model` is passed in the API request body. Some servers ignore this if started with a fixed model, but it's good practice to send it.

### Step 3: Create LocalWhisperTranscriptionService

New file: `app/Services/LocalWhisperTranscriptionService.php`

```php
class LocalWhisperTranscriptionService implements TranscriptionServiceInterface
{
    use DetectsStorageType;

    public function __construct(
        private readonly SermonProcessingLogger $logger,
        private readonly TranscriptStorageService $storageService,
        private readonly AudioChunkingService $chunkingService,
        private readonly TranscriptFormatterService $formatter,
    ) {}

    public function transcribe(
        string $audioFilePath,
        string $processingId = 'unknown',
        ?string $disk = null
    ): string {
        // Same file resolution logic as AudioTranscriptionService
        // (validate file exists, download from S3 if needed, compress if needed)
        // ...

        // Call local Whisper server instead of OpenAI
        $transcript = $this->transcribeFile($processedFilePath, $processingId);

        return $transcript;
    }

    private function transcribeFile(string $filePath, string $processingId): string
    {
        $baseUrl = config('media-processing.transcription.local_whisper_url');
        $model = config('media-processing.transcription.local_whisper_model');
        $timeout = config('media-processing.transcription.local_whisper_timeout');

        $response = Http::timeout($timeout)
            ->attach('file', fopen($filePath, 'r'), basename($filePath))
            ->post("{$baseUrl}/v1/audio/transcriptions", [
                'model' => $model,
                'language' => 'en',
                'response_format' => 'text',
                'prompt' => 'The following speech is a Christian sermon preached at Crockenhill Baptist Church, in the British conservative evangelical tradition.',
            ]);

        if ($response->failed()) {
            throw new TranscriptionException(
                "Local Whisper transcription failed: HTTP {$response->status()}"
            );
        }

        $transcript = $response->body();

        // Validate and format (same pipeline as OpenAI service)
        if (empty($transcript) || !$this->validateTranscript($transcript)) {
            throw new TranscriptionException('Local Whisper returned invalid transcript');
        }

        return $this->formatter->formatAsMarkdown($transcript);
    }

    // Storage methods delegate to TranscriptStorageService (identical to AudioTranscriptionService)
    // storeTranscript(), getTranscript(), transcriptExists(), deleteTranscript(),
    // cleanupOnFailure(), getTranscriptPath()
}
```

**Design decisions:**

1. **Uses Laravel HTTP client, not the OpenAI PHP library.** The endpoint is a simple multipart POST returning plain text. Using `Http::attach()` avoids coupling to the `openai-php` library's response parsing, which may not match local server responses exactly. It's also fewer dependencies and easier to debug.

2. **No API key validation.** The `ensureApiKeyConfigured()` check in `AudioTranscriptionService` would fail for local usage. The local service simply skips it.

3. **Reuses the same support services.** `AudioChunkingService` (for long files), `TranscriptFormatterService` (markdown formatting + British English), and `TranscriptStorageService` (file I/O) are all injected and used identically. The only difference is the API call itself.

4. **No retry/backoff logic in the service.** Same philosophy as `AudioTranscriptionService` — the job layer (`TranscribeAudio`) owns retry timing. The service throws typed exceptions and the job decides whether to requeue.

5. **Keeps the 25MB file size check and chunking.** Even though local Whisper has no hard file size limit, chunking large files prevents memory exhaustion and keeps individual transcription calls predictable. The existing pipeline works well — no reason to change it.

6. **Simpler error handling.** No need to classify OpenAI-specific HTTP error codes (401, 413). Local failures are either connection errors (retryable) or server errors (log and retry).

### Step 4: Extract Shared Logic (Optional Refactor)

`AudioTranscriptionService` and `LocalWhisperTranscriptionService` share significant code:
- File resolution (disk detection, S3 download, compression)
- Chunking logic
- Storage delegation methods (6 methods)
- Transcript validation

**Option A: Trait** — Extract shared file-handling and storage methods into a `TranscribesAudio` trait. Both services use it and only override the actual API call.

**Option B: Abstract base class** — `AbstractTranscriptionService` handles everything except the raw API call, which is a protected abstract method.

**Option C: Keep duplication** — Two independent services, easier to reason about, but ~150 lines of duplicated code.

**Recommendation: Option B (abstract base class).** The duplication is substantial (file resolution, compression, chunking, 6 storage delegation methods). An abstract class with a single `protected abstract function callTranscriptionApi(string $filePath, string $processingId): string` keeps things DRY without over-abstracting.

```
AbstractTranscriptionService (shared logic)
├── AudioTranscriptionService (OpenAI API call)
└── LocalWhisperTranscriptionService (HTTP call to local server)
```

However, this refactor touches production code. **If you prefer minimal risk, go with Option C** — duplicate the non-API logic into the new service and refactor later.

### Step 5: Update Service Provider

In `AiServiceProvider`:

```php
$this->app->bind(TranscriptionServiceInterface::class, function ($app): TranscriptionServiceInterface {
    $serviceType = config('media-processing.transcription.service', 'openai');

    return match ($serviceType) {
        'mock' => $app->make(MockTranscriptionService::class),
        'local' => $app->make(LocalWhisperTranscriptionService::class),
        default => $app->make(AudioTranscriptionService::class),
    };
});
```

### Step 6: Update .env

```env
TRANSCRIPTION_SERVICE_TYPE=local
```

That's it. No other env changes needed — the Docker service URL defaults to `http://whisper:8000` within the Sail network.

### Step 7: Test

1. Start the Whisper container: `docker compose --profile whisper up -d`
2. Wait for model download (first run only, check with `docker compose logs whisper`)
3. Upload a short test audio file through the admin UI
4. Verify transcription completes without OpenAI API calls
5. Write a feature test that mocks the HTTP call to the local Whisper endpoint

---

## File Changes Summary

| File | Change |
|------|--------|
| `docker-compose.yml` | Add `whisper` service with profile |
| `config/media-processing.php` | Add `local_whisper_url`, `local_whisper_model`, `local_whisper_timeout` |
| `app/Services/LocalWhisperTranscriptionService.php` | **New** — local Whisper implementation |
| `app/Providers/AiServiceProvider.php` | Add `'local'` case to match statement |
| `.env` | Set `TRANSCRIPTION_SERVICE_TYPE=local` |
| `.env.example` | Add local whisper env vars with comments |
| `tests/Feature/LocalWhisperTranscriptionTest.php` | **New** — test the service |

**Production code changes:** 2 lines in `AiServiceProvider.php`, 3 lines in `config/media-processing.php`. Everything else is new files or dev-only config.

---

## Alternative Considered: Override OPENAI_BASE_URL

The `openai-php/laravel` package supports `OPENAI_BASE_URL` for pointing at compatible APIs. Setting this to the local Whisper server would require zero code changes.

**Rejected because:**
1. It redirects ALL OpenAI calls (sermon analysis via GPT, section classification, email extraction), not just transcription
2. `AudioTranscriptionService::ensureApiKeyConfigured()` throws if no API key is set — local Whisper doesn't need one
3. The model name `gpt-4o-transcribe` would be sent to the local server, which expects `small` / `medium` / etc.
4. OpenAI-specific error handling (`ErrorException`, `TransporterException`) may not apply to local server responses

A dedicated service is cleaner and avoids these issues.

---

## Cost/Benefit

| | OpenAI API | Local Whisper |
|---|-----------|---------------|
| **Cost per transcription** | ~£1 | £0 |
| **Transcription speed (30 min sermon)** | ~30 seconds | ~5-15 minutes (`small` on CPU) |
| **Accuracy** | Excellent (`gpt-4o-transcribe`) | Good (`small`), Very Good (`medium`) |
| **Setup effort** | None (already working) | One-time Docker + service implementation |
| **Infrastructure** | Internet + API key required | Runs fully offline |

The speed tradeoff is acceptable for manual testing — you upload a livestream and check results later, not interactively waiting. For quick iteration on non-transcription features, `TRANSCRIPTION_SERVICE_TYPE=mock` remains the fastest option.
