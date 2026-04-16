<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelMediaProcessingRequest;
use App\Http\Requests\ConfirmMediaSegmentRequest;
use App\Http\Requests\MediaStatusRequest;
use App\Http\Requests\ProcessMediaRequest;
use App\Http\Requests\RetryMediaProcessingRequest;
use App\Services\UnifiedMediaProcessor;
use App\Services\VideoProcessingOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MediaController extends Controller
{
    public function __construct(
        private readonly UnifiedMediaProcessor $mediaProcessor,
    ) {}

    /**
     * Upload and process media file - handles all types (audio, video, livestream)
     */
    public function upload(ProcessMediaRequest $request, string $type): JsonResponse
    {
        $mediaType = MediaType::tryFrom($type);
        if ($mediaType === null) {
            return response()->json([
                'success' => false,
                'message' => "Unsupported media type: {$type}",
                'error_code' => 'INVALID_MEDIA_TYPE',
            ], 400);
        }

        $validated = $request->validated();

        try {
            $file = $request->file('file');

            Log::info('Media upload initiated', [
                'type' => $type,
                'user_id' => $request->user()?->id,
                'filename' => $this->sanitizeForLog($file->getClientOriginalName()),
                'size' => $file->getSize(),
            ]);

            $options = $this->processingOptions($validated);
            $result = $options === []
                ? $this->mediaProcessor->process($type, $file)
                : $this->mediaProcessor->process($type, $file, options: $options);

            if ($result->success) {
                return response()->json($result->toArray(), 202);
            } else {
                return response()->json($result->toArray(), 422);
            }

        } catch (\Exception $e) {
            Log::error('Media upload failed', [
                'type' => $type,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Media upload failed',
                'error_code' => 'UPLOAD_FAILED',
            ], 500);
        }
    }

    /**
     * Get processing status - unified for all media types
     */
    public function status(MediaStatusRequest $request, string $processingId): JsonResponse
    {
        // Validate processing ID format
        if (! $this->isValidProcessingId($processingId)) {
            return response()->json([
                'found' => false,
                'message' => 'Invalid processing ID format',
            ], 400);
        }

        $validated = $request->validated();

        try {
            $includeLogs = (bool) ($validated['include_logs'] ?? false);
            $logLimit = (int) ($validated['log_limit'] ?? 20);

            $response = $includeLogs
                ? $this->mediaProcessor->getStatusWithLogs($processingId, true, $logLimit)
                : $this->mediaProcessor->getStatus($processingId);

            if (! $response->found) {
                return response()->json($response->toArray(), 404);
            }

            return response()->json($response->toArray(), 200);

        } catch (\Exception $e) {
            Log::error('Status check failed', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $message = $e instanceof \App\Contracts\ProvidesSafeMessage
                ? $e->getSafeMessage()
                : 'Status check failed due to an internal error.';

            return response()->json(['found' => false, 'message' => $message], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelProcessing(string $processingId): array
    {
        return $this->mediaProcessor->cancel($processingId);
    }

    /**
     * Cancel processing
     */
    public function cancel(CancelMediaProcessingRequest $request, string $processingId): JsonResponse
    {
        // Validate processing ID format
        if (! $this->isValidProcessingId($processingId)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid processing ID format',
            ], 400);
        }

        try {
            $result = $this->cancelProcessing($processingId);

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Cancel failed'], 500);
        }
    }

    /**
     * Confirm a sermon segment for a livestream run awaiting manual review
     */
    public function confirmSegment(ConfirmMediaSegmentRequest $request, string $processingId, ConfirmLivestreamSermonSegment $action): JsonResponse
    {
        if (! $this->isValidProcessingId($processingId)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid processing ID format',
            ], 400);
        }

        /** @var \App\Models\User $user */
        $user = $request->user();

        try {
            $action->execute($processingId, (int) $request->input('segment_id'), $user);

            return response()->json([
                'success' => true,
                'message' => 'Sermon segment confirmed. Processing has been resumed.',
                'status_url' => route('api.media.processing.status', ['processingId' => $processingId]),
            ], 202);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Segment confirmation failed', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
            ]);

            return response()->json(['success' => false, 'message' => 'Confirmation failed due to an internal error.'], 500);
        }
    }

    /**
     * Retry processing
     */
    public function retry(RetryMediaProcessingRequest $request, string $processingId): JsonResponse
    {
        // Validate processing ID format
        if (! $this->isValidProcessingId($processingId)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid processing ID format',
            ], 400);
        }

        try {
            $result = $this->mediaProcessor->retry($processingId);

            return response()->json($result->toArray(), $result->success ? 202 : 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Retry failed'], 500);
        }
    }

    /**
     * Validate processing ID format (UUID v4 only).
     */
    private function isValidProcessingId(string $processingId): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $processingId
        );
    }

    /**
     * Sanitize user-controlled strings before writing to logs.
     */
    private function sanitizeForLog(string $value): string
    {
        $withoutControlChars = str_replace(["\r", "\n", "\t"], ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $withoutControlChars));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function processingOptions(array $validated): array
    {
        return VideoProcessingOptions::forVideo(
            (bool) ($validated['auto_trim'] ?? false),
            isset($validated['video_processing_mode']) && is_string($validated['video_processing_mode'])
                ? $validated['video_processing_mode']
                : null
        );
    }
}
