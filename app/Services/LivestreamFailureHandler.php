<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProvidesSafeMessage;
use App\Mail\LivestreamProcessingFailed;
use App\Models\MediaProcessingLog;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LivestreamFailureHandler
{
    public function __construct(
        private readonly VideoStorageService $storageService
    ) {}

    public function handle(string $processingId, \Throwable $e): void
    {
        Log::error('Livestream processing failure', [
            'processing_id' => $processingId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $processingLog = MediaProcessingLog::where('processing_id', $processingId)->first();

        if ($processingLog) {
            $message = $e instanceof ProvidesSafeMessage
                ? $e->getSafeMessage()
                : 'An internal error occurred during livestream processing.';

            $processingLog->markAsFailed($message);

            $tempFiles = [];
            if ($processingLog->source_file_path) {
                $tempFiles[] = $processingLog->source_file_path;
            }

            $metadata = $processingLog->processing_metadata ?? [];
            foreach (['extracted_segment_path', 'extracted_audio_path', 'temp_video_path'] as $key) {
                if (isset($metadata[$key])) {
                    $tempFiles[] = $metadata[$key];
                }
            }

            if (! empty($tempFiles)) {
                $this->storageService->cleanupTemporaryFiles($tempFiles);
            }
        }

        try {
            Mail::to(config('media-processing.email.admin_email'))
                ->queue(new LivestreamProcessingFailed($processingId, $e));
        } catch (Exception $emailException) {
            Log::warning('Failed to queue livestream processing failure email, continuing', [
                'processing_id' => $processingId,
                'original_error' => $e->getMessage(),
                'email_error' => $emailException->getMessage(),
            ]);
        }
    }
}
