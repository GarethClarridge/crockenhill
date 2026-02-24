<?php

namespace App\Services;

class ProcessingResult
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $processingId,
        public readonly string $message,
        public readonly ?string $statusUrl = null,
        public readonly ?string $errorCode = null,
        public readonly ?array $details = null
    ) {}

    /**
     * @param  array<string, mixed>|null  $details
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

    public static function failure(
        string $processingId,
        string $message,
        string $errorCode
    ): self {
        return new self(
            success: false,
            processingId: $processingId,
            message: $message,
            errorCode: $errorCode
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'success' => $this->success,
            'message' => $this->message,
            'processing_id' => $this->processingId,
        ];

        if ($this->statusUrl) {
            $data['status_url'] = $this->statusUrl;
        }

        if ($this->errorCode) {
            $data['error_code'] = $this->errorCode;
        }

        if ($this->details) {
            $data['details'] = $this->details;
        }

        return $data;
    }
}
