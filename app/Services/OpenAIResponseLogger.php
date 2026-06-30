<?php

declare(strict_types=1);

namespace App\Services;

use App\Traits\SanitizesLogData;
use Illuminate\Support\Facades\Log;

/**
 * Logs raw OpenAI API responses for debugging malformed response issues.
 * Wraps the OpenAI SDK to capture HTTP response details.
 */
class OpenAIResponseLogger
{
    use SanitizesLogData;

    /**
     * Log detailed information about an OpenAI API response
     */
    public static function logResponse(
        string $processingId,
        int $attempt,
        mixed $responseData,
        ?string $responseType = null
    ): void {
        $dataType = gettype($responseData);
        $logData = [
            'processing_id' => $processingId,
            'attempt' => $attempt,
            'response_data_type' => $dataType,
            'response_type_hint' => $responseType,
        ];

        if (is_string($responseData)) {
            // Log first 500 chars of string response (could be HTML error)
            $logData['response_content_preview'] = substr($responseData, 0, 500);
            $logData['response_length'] = strlen($responseData);
            $logData['is_json'] = json_decode($responseData) !== null ? 'yes' : 'no';

            // Check for common error indicators
            if (str_contains(strtolower($responseData), 'html')) {
                $logData['likely_html_error'] = true;
            }
            if (str_contains(strtolower($responseData), 'unauthorized')) {
                $logData['likely_auth_error'] = true;
            }
            if (str_contains(strtolower($responseData), 'rate limit')) {
                $logData['likely_rate_limit'] = true;
            }
        } elseif (is_array($responseData)) {
            $logData['response_keys'] = array_keys($responseData);
            $logData['response_structure_valid'] = true;
        }

        Log::warning('OpenAI API response type analysis', self::sanitizeArrayForLog($logData));
    }

    /**
     * Log HTTP transport layer details
     */
    public static function logTransportError(
        string $processingId,
        int $attempt,
        string $errorMessage,
        ?int $statusCode = null,
        ?string $responseBody = null
    ): void {
        $logData = [
            'processing_id' => $processingId,
            'attempt' => $attempt,
            'error_message' => $errorMessage,
            'status_code' => $statusCode,
        ];

        if ($responseBody) {
            $logData['response_body_preview'] = substr($responseBody, 0, 500);
            $logData['response_body_length'] = strlen($responseBody);

            // Try to parse as JSON to understand error structure
            $decoded = json_decode($responseBody, true);
            if ($decoded) {
                $logData['error_response_json'] = $decoded;
            } else {
                $logData['response_is_not_json'] = true;
            }
        }

        Log::error('OpenAI API transport layer error', self::sanitizeArrayForLog($logData));
    }
}
