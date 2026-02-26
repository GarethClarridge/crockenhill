<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\ProcessingStatusContract;
use App\Data\StandardProcessingResponse;
use App\Enums\ApiTokenAbility;
use App\Enums\MediaType;
use App\Http\Controllers\Controller;
use App\Services\MediaValidationService;
use App\Services\UnifiedMediaProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MediaController extends Controller implements ProcessingStatusContract
{
    public function __construct(
        private readonly UnifiedMediaProcessor $mediaProcessor,
        private readonly MediaValidationService $validation
    ) {}

    /**
     * Upload and process media file - handles all types (audio, video, livestream)
     */
    public function upload(Request $request, string $type): JsonResponse
    {
        if (($abilityResponse = $this->ensureMediaProcessAbility($request)) !== null) {
            return $abilityResponse;
        }

        $mediaType = MediaType::tryFrom($type);
        if ($mediaType === null) {
            return response()->json([
                'success' => false,
                'message' => "Unsupported media type: {$type}",
                'error_code' => 'INVALID_MEDIA_TYPE',
            ], 400);
        }

        $request->validate($this->validation->rulesForType($mediaType));

        try {
            $file = $request->file('file');

            Log::info('Media upload initiated', [
                'type' => $type,
                'user_id' => $request->user()?->id,
                'filename' => $this->sanitizeForLog($file->getClientOriginalName()),
                'size' => $file->getSize(),
            ]);

            $result = $this->mediaProcessor->process($type, $file);

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
    public function status(Request $request, string $processingId): JsonResponse
    {
        if (($abilityResponse = $this->ensureMediaProcessAbility($request)) !== null) {
            return $abilityResponse;
        }

        // Validate processing ID format
        if (! $this->isValidProcessingId($processingId)) {
            return response()->json([
                'found' => false,
                'message' => 'Invalid processing ID format',
            ], 400);
        }

        try {
            $includeLogs = $request->boolean('include_logs');
            $logLimit = $request->integer('log_limit', 20);

            $response = $includeLogs
                ? $this->getProcessingStatusWithLogs($processingId, true, $logLimit)
                : $this->getProcessingStatus($processingId);

            if (! $response->found) {
                return response()->json($response->toArray(), 404);
            }

            return response()->json($response->toArray(), 200);

        } catch (\Exception $e) {
            Log::error('Status check failed', [
                'processing_id' => $processingId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['found' => false, 'message' => 'Status check failed'], 500);
        }
    }

    // Implement ProcessingStatusContract methods
    public function getProcessingStatus(string $processingId): StandardProcessingResponse
    {
        return $this->mediaProcessor->getStatus($processingId);
    }

    public function getProcessingStatusWithLogs(string $processingId, bool $includeLogs = false, int $logLimit = 20): StandardProcessingResponse
    {
        // Delegate to the media processor which knows how to handle logs properly
        return $this->mediaProcessor->getStatusWithLogs($processingId, $includeLogs, $logLimit);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelProcessing(string $processingId): array
    {
        return $this->mediaProcessor->cancel($processingId);
    }

    public function canHandle(string $processingId): bool
    {
        return $this->mediaProcessor->canHandle($processingId);
    }

    /**
     * Cancel processing
     */
    public function cancel(Request $request, string $processingId): JsonResponse
    {
        if (($abilityResponse = $this->ensureMediaProcessAbility($request)) !== null) {
            return $abilityResponse;
        }

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
     * Retry processing
     */
    public function retry(Request $request, string $processingId): JsonResponse
    {
        if (($abilityResponse = $this->ensureMediaProcessAbility($request)) !== null) {
            return $abilityResponse;
        }

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

    private function ensureMediaProcessAbility(Request $request): ?JsonResponse
    {
        if ($request->bearerToken() === null) {
            return null;
        }

        if ($request->user()?->tokenCan(ApiTokenAbility::MEDIA_PROCESS->value)) {
            return null;
        }

        return response()->json([
            'message' => 'Missing required token ability: '.ApiTokenAbility::MEDIA_PROCESS->value,
        ], 403);
    }
}
