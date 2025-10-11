<?php

namespace App\Services;

use App\Contracts\VideoProcessingServiceInterface;
use App\Data\LivestreamProcessingResult;
use App\Data\StandardProcessingResponse;
use Illuminate\Http\UploadedFile;

class VideoProcessingService implements VideoProcessingServiceInterface
{
    public function __construct(
        private LivestreamSegmentationService $segmentationService,
        private LivestreamStatusService $statusService
    ) {}

    public function processLivestream(UploadedFile $videoFile): ProcessingResult
    {
        return $this->segmentationService->processWithSegmentation($videoFile);
    }

    /**
     * Process video directly without segmentation (for sermon-only videos)
     */
    public function processDirectly(UploadedFile $videoFile): ProcessingResult
    {
        // For direct processing, we skip segmentation and process the entire video
        return $this->segmentationService->processDirectly($videoFile);
    }

    /**
     * Process video with segmentation (for livestream videos)
     */
    public function processWithSegmentation(UploadedFile $videoFile): ProcessingResult
    {
        return $this->segmentationService->processWithSegmentation($videoFile);
    }

    public function getProcessingStatus(string $processingId): StandardProcessingResponse
    {
        return $this->statusService->getProcessingStatus($processingId);
    }

    public function getProcessingResult(string $processingId): LivestreamProcessingResult
    {
        return $this->statusService->getProcessingResult($processingId);
    }

    public function retryProcessing(string $processingId): LivestreamProcessingResult
    {
        return $this->segmentationService->retryProcessing($processingId);
    }

    public function cancelProcessing(string $processingId): bool
    {
        return $this->segmentationService->cancelProcessing($processingId);
    }

    /**
     * Update the processing status for real-time progress tracking
     */
    public function updateProcessingStatus(string $processingId, string $status): void
    {
        $this->segmentationService->updateProcessingStatus($processingId, $status);
    }

    public function getProcessingSummary(): array
    {
        return $this->statusService->getProcessingSummary();
    }
}
