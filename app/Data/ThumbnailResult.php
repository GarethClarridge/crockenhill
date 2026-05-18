<?php

declare(strict_types=1);

namespace App\Data;

final readonly class ThumbnailResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $success,
        public ?string $thumbnailPath = null,
        public ?string $errorMessage = null,
        public array $metadata = []
    ) {}

    /**
     * Create a successful thumbnail generation result
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function success(string $thumbnailPath, array $metadata = []): self
    {
        return new self(
            success: true,
            thumbnailPath: $thumbnailPath,
            metadata: $metadata
        );
    }

    /**
     * Create a skipped thumbnail generation result
     */
    public static function skipped(string $reason): self
    {
        return new self(
            success: false,
            errorMessage: $reason
        );
    }

    /**
     * Create a failed thumbnail generation result
     */
    public static function failed(string $errorMessage): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage
        );
    }

    /**
     * Check if thumbnail generation was successful
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if thumbnail generation was skipped
     */
    public function isSkipped(): bool
    {
        return ! $this->success && $this->errorMessage !== null;
    }

    /**
     * Check if thumbnail generation failed
     */
    public function isFailed(): bool
    {
        return ! $this->success;
    }

    /**
     * Get the thumbnail path if successful
     */
    public function getThumbnailPath(): ?string
    {
        return $this->success ? $this->thumbnailPath : null;
    }

    /**
     * Get error message if failed or skipped
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * Get metadata array
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get specific metadata value by key
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
