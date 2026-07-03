<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ConfirmLivestreamSermonSegment;
use App\Actions\GetMediaProcessingStatus;
use App\Contracts\ProvidesSafeMessage;
use App\Data\VideoProcessingOptions;
use App\Enums\MediaType;
use App\Exceptions\InvalidFileException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelMediaProcessingRequest;
use App\Http\Requests\ConfirmMediaSegmentRequest;
use App\Http\Requests\MediaStatusRequest;
use App\Http\Requests\MediaStreamRequest;
use App\Http\Requests\ProcessMediaRequest;
use App\Http\Requests\RetryMediaProcessingRequest;
use App\Models\User;
use App\Services\Processing\UnifiedMediaProcessor;
use App\Traits\SanitizesLogData;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    use SanitizesLogData;

    public function __construct(
        private readonly UnifiedMediaProcessor $mediaProcessor,
    ) {}

    /**
     * Upload and process media file - handles all types (audio, video, livestream)
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     * Stack traces are sanitized to prevent information leakage.
     *
     * @throws UniqueConstraintViolationException If a duplicate race occurs
     * @throws InvalidFileException If the file fails initial validation
     * @throws \Exception For underlying service or storage failures
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

            Log::info('Media upload initiated', $this->sanitizeArrayForLog([
                'type' => $type,
                'user_id' => $request->user()?->id,
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ]));

            $options = $this->processingOptions($type, $validated);
            $result = $options === []
                ? $this->mediaProcessor->process($type, $file)
                : $this->mediaProcessor->process($type, $file, options: $options);

            if ($result->success) {
                return response()->json($result->toArray(), 202);
            }

            return response()->json($result->toArray(), 422);

        } catch (\Exception $e) {
            return $this->handleApiException($e, 'Media upload failed', [
                'type' => $type,
                'user_id' => $request->user()?->id,
            ], ['success' => false, 'message' => 'Media upload failed', 'error_code' => 'UPLOAD_FAILED']);
        }
    }

    /**
     * Get processing status - unified for all media types
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     * Stack traces are sanitized to prevent information leakage.
     *
     * @throws \Exception If the status check fails
     */
    public function status(MediaStatusRequest $request, string $processingId): JsonResponse
    {
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
            $message = $e instanceof ProvidesSafeMessage
                ? $e->getSafeMessage()
                : 'Status check failed due to an internal error.';

            return $this->handleApiException($e, 'Status check failed', [
                'processing_id' => $this->sanitizeForLog($processingId),
            ], ['found' => false, 'message' => $message]);
        }
    }

    /**
     * Stream processing status as Server-Sent Events.
     *
     * Replaces poll-based status checks with a single long-lived connection that emits a `progress`
     * event whenever the snapshot changes. The connection self-terminates on a terminal status
     * (completed, failed, cancelled) or after a one-hour deadline.
     *
     * Security: The underlying GetMediaProcessingStatus service enforces per-user visibility.
     * Strict processingId shape validation is enforced via MediaStreamRequest.
     */
    public function stream(MediaStreamRequest $request, string $processingId, GetMediaProcessingStatus $statusService): StreamedResponse
    {
        $pollSeconds = max(0, (int) config('media-processing.sse.poll_seconds', 2));
        $maxDurationSeconds = max(1, (int) config('media-processing.sse.max_duration_seconds', 3600));

        return response()->eventStream(function () use ($processingId, $statusService, $pollSeconds, $maxDurationSeconds) {
            $lastHash = null;
            $deadline = now()->addSeconds($maxDurationSeconds);

            while (now()->lt($deadline)) {
                $snapshot = $statusService->get($processingId)->toArray();
                $hash = md5((string) json_encode($snapshot));

                if ($hash !== $lastHash) {
                    yield new StreamedEvent(event: 'progress', data: $snapshot);
                    $lastHash = $hash;
                }

                $status = is_string($snapshot['status'] ?? null) ? $snapshot['status'] : null;
                if (in_array($status, ['completed', 'failed', 'cancelled'], true)) {
                    return;
                }

                if ($pollSeconds > 0) {
                    sleep($pollSeconds);
                }
            }
        }, endStreamWith: new StreamedEvent(event: 'progress', data: '</stream>'));
    }

    /**
     * Cancel processing
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     * Stack traces are sanitized to prevent information leakage.
     *
     * @throws \Exception If the cancellation fails
     */
    public function cancel(CancelMediaProcessingRequest $request, string $processingId): JsonResponse
    {
        try {
            $result = $this->cancelProcessing($processingId);

            if ($result['success']) {
                Log::warning('Media processing cancelled via API', $this->sanitizeArrayForLog([
                    'processing_id' => $processingId,
                    'user_id' => $request->user()?->id,
                ]));
            }

            return response()->json($result, $result['success'] ? 200 : 400);
        } catch (\Exception $e) {
            return $this->handleApiException($e, 'Media cancellation failed', [
                'processing_id' => $this->sanitizeForLog($processingId),
                'user_id' => $request->user()?->id,
            ], ['success' => false, 'message' => 'Cancel failed']);
        }
    }

    /**
     * Confirm a sermon segment for a livestream run awaiting manual review
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     * Stack traces are sanitized to prevent information leakage.
     *
     * @throws \InvalidArgumentException If parameters are invalid
     * @throws \Exception If the confirmation fails
     */
    public function confirmSegment(ConfirmMediaSegmentRequest $request, string $processingId, ConfirmLivestreamSermonSegment $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $action->execute($processingId, (int) $request->input('segment_id'), $user);

            Log::warning('Sermon segment confirmed via API', $this->sanitizeArrayForLog([
                'processing_id' => $processingId,
                'segment_id' => $request->input('segment_id'),
                'user_id' => $user->id,
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Sermon segment confirmed. Processing has been resumed.',
                'status_url' => route('api.media.processing.status', ['processingId' => $this->sanitizeForLog($processingId)]),
            ], 202);
        } catch (\InvalidArgumentException $e) {
            $message = $e instanceof ProvidesSafeMessage
                ? $e->getSafeMessage()
                : 'Invalid request parameters.';

            return response()->json(['success' => false, 'message' => $message], 422);
        } catch (\Exception $e) {
            return $this->handleApiException($e, 'Segment confirmation failed', [
                'processing_id' => $this->sanitizeForLog($processingId),
                'user_id' => $request->user()?->id,
            ], ['success' => false, 'message' => 'Confirmation failed due to an internal error.']);
        }
    }

    /**
     * Retry processing
     *
     * Security: Log data is sanitized to prevent log injection from user-controlled metadata.
     * Stack traces are sanitized to prevent information leakage.
     *
     * @throws \Throwable If the retry initiation fails
     */
    public function retry(RetryMediaProcessingRequest $request, string $processingId): JsonResponse
    {
        try {
            $result = $this->mediaProcessor->retry($processingId);

            if ($result->success) {
                Log::warning('Media processing retry initiated via API', $this->sanitizeArrayForLog([
                    'processing_id' => $processingId,
                    'user_id' => $request->user()?->id,
                ]));
            }

            return response()->json($result->toArray(), $result->success ? 202 : 422);
        } catch (\Exception $e) {
            return $this->handleApiException($e, 'Media retry failed', [
                'processing_id' => $this->sanitizeForLog($processingId),
                'user_id' => $request->user()?->id,
            ], ['success' => false, 'message' => 'Retry failed']);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $body
     */
    private function handleApiException(\Exception $e, string $logMessage, array $context = [], array $body = []): JsonResponse
    {
        Log::error($logMessage, $this->sanitizeArrayForLog(array_merge($context, [
            'error' => $e->getMessage(),
            'trace' => $this->sanitizeStackTrace($e->getTraceAsString()),
        ])));

        if ($body === []) {
            $body = ['success' => false, 'message' => $logMessage];
        }

        return response()->json($body, 500);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function processingOptions(string $type, array $validated): array
    {
        return VideoProcessingOptions::forMediaType(
            $type,
            (bool) ($validated['auto_trim'] ?? false),
            isset($validated['video_processing_mode']) && is_string($validated['video_processing_mode'])
                ? $validated['video_processing_mode']
                : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelProcessing(string $processingId): array
    {
        return $this->mediaProcessor->cancel($processingId);
    }
}
