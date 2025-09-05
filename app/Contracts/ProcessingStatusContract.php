<?php

namespace App\Contracts;

use App\Data\StandardProcessingResponse;

/**
 * Contract for processing status controllers to ensure consistent API responses
 */
interface ProcessingStatusContract
{
    /**
     * Get processing status for a given processing ID
     *
     * @param  string  $processingId  The processing ID to check status for
     * @return StandardProcessingResponse The standardized processing response
     */
    public function getProcessingStatus(string $processingId): StandardProcessingResponse;

    /**
     * Cancel processing for a given processing ID
     *
     * @param  string  $processingId  The processing ID to cancel
     * @return array The cancellation response
     */
    public function cancelProcessing(string $processingId): array;

    /**
     * Determine if this controller can handle the given processing ID
     *
     * @param  string  $processingId  The processing ID to check
     * @return bool True if this controller can handle the processing ID
     */
    public function canHandle(string $processingId): bool;
}
