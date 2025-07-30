<?php

namespace App\Services;

class ProcessingResult
{
  public function __construct(
    public readonly bool $success,
    public readonly ?string $processingId = null,
    public readonly ?string $message = null,
    public readonly ?string $statusUrl = null,
    public readonly ?string $errorCode = null,
    public readonly ?array $details = null
  ) {}

  /**
   * Create a successful processing result
   */
  public static function success(
    string $processingId,
    string $message,
    ?string $statusUrl = null,
    ?array $details = null
  ): self {
    return new self(
      success: true,
      processingId: $processingId,
      message: $message,
      statusUrl: $statusUrl,
      details: $details
    );
  }

  /**
   * Create an error processing result
   */
  public static function error(
    string $message,
    ?string $errorCode = null,
    ?array $details = null
  ): self {
    return new self(
      success: false,
      message: $message,
      errorCode: $errorCode,
      details: $details
    );
  }

  /**
   * Convert to array for API responses
   */
  public function toArray(): array
  {
    $result = [
      'success' => $this->success,
      'message' => $this->message,
    ];

    if ($this->success) {
      $result['processing_id'] = $this->processingId;
      if ($this->statusUrl) {
        $result['status_url'] = $this->statusUrl;
      }
    } else {
      if ($this->errorCode) {
        $result['error_code'] = $this->errorCode;
      }
    }

    if ($this->details) {
      $result['details'] = $this->details;
    }

    return $result;
  }
}
