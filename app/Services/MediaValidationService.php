<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;

class MediaValidationService
{
    /** @var array<string> */
    private const SUPPORTED_TYPES = ['audio', 'video', 'livestream'];

    /**
     * Return Laravel validation rules for a given media type.
     *
     * @return array<string, string>
     */
    public function rulesForType(string $type): array
    {
        $this->assertValidType($type);

        $config = config("media-processing.types.{$type}");
        $maxSizeKB = (int) ($config['max_file_size'] / 1024);
        $extensions = implode(',', $config['allowed_extensions']);

        return [
            'file' => "required|file|mimes:{$extensions}|max:{$maxSizeKB}",
        ];
    }

    /**
     * Return human-readable max file size string for display.
     */
    public function maxFileSizeForDisplay(string $type): string
    {
        $this->assertValidType($type);

        $bytes = config("media-processing.types.{$type}.max_file_size");

        if ($bytes >= 1024 * 1024 * 1024) {
            return ((int) ($bytes / (1024 * 1024 * 1024))).'GB';
        }

        return ((int) ($bytes / (1024 * 1024))).'MB';
    }

    /**
     * Return comma-separated list of allowed extensions for display.
     */
    public function allowedExtensionsForDisplay(string $type): string
    {
        $this->assertValidType($type);

        $extensions = config("media-processing.types.{$type}.allowed_extensions");

        return implode(', ', array_map('strtoupper', $extensions));
    }

    /**
     * Return the accept attribute value for an HTML file input.
     */
    public function acceptAttribute(string $type): string
    {
        $this->assertValidType($type);

        $extensions = config("media-processing.types.{$type}.allowed_extensions");

        return implode(',', array_map(fn (string $ext) => ".{$ext}", $extensions));
    }

    /**
     * Return the max file size in bytes for a given type.
     */
    public function maxFileSizeBytes(string $type): int
    {
        $this->assertValidType($type);

        return (int) config("media-processing.types.{$type}.max_file_size");
    }

    /**
     * @return array<string>
     */
    public function allowedExtensions(string $type): array
    {
        $this->assertValidType($type);

        return config("media-processing.types.{$type}.allowed_extensions", []);
    }

    /**
     * @return array<string>
     */
    public function allowedMimes(string $type): array
    {
        $this->assertValidType($type);

        return config("media-processing.types.{$type}.allowed_mimes", []);
    }

    /**
     * Validate a local file path against the canonical rules for a given media type.
     *
     * Throws \InvalidArgumentException on the first failing constraint.
     */
    public function validateLocalFile(string $type, string $filePath): void
    {
        $this->assertValidType($type);

        $maxSize = $this->maxFileSizeBytes($type);
        $fileSize = filesize($filePath);

        if ($fileSize === false || $fileSize > $maxSize) {
            $maxSizeDisplay = $this->maxFileSizeForDisplay($type);
            throw new \InvalidArgumentException("File size exceeds maximum limit of {$maxSizeDisplay}");
        }

        $mimeType = mime_content_type($filePath);
        if ($mimeType === false || ! in_array($mimeType, $this->allowedMimes($type), true)) {
            $display = $this->allowedExtensionsForDisplay($type);
            throw new \InvalidArgumentException("Invalid file type. Supported formats: {$display}.");
        }
    }

    /**
     * Validate an uploaded file against the canonical rules for a given media type.
     *
     * Throws \InvalidArgumentException on the first failing constraint.
     */
    public function validateUploadedFile(string $type, UploadedFile $file): void
    {
        $this->assertValidType($type);

        if (! $file->isValid()) {
            throw new \InvalidArgumentException('Uploaded file is corrupted or invalid');
        }

        $maxSize = $this->maxFileSizeBytes($type);
        if ($file->getSize() > $maxSize) {
            $maxSizeDisplay = $this->maxFileSizeForDisplay($type);
            throw new \InvalidArgumentException("File size exceeds maximum limit of {$maxSizeDisplay}");
        }

        $allowedMimes = $this->allowedMimes($type);
        if (! in_array($file->getMimeType(), $allowedMimes, true)) {
            $display = $this->allowedExtensionsForDisplay($type);
            throw new \InvalidArgumentException("Invalid file type. Supported formats: {$display}.");
        }

        $allowedExtensions = $this->allowedExtensions($type);
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $allowedExtensions, true)) {
            throw new \InvalidArgumentException('Invalid file extension. Allowed: '.implode(', ', $allowedExtensions));
        }
    }

    /**
     * @return array<string>
     */
    public function supportedTypes(): array
    {
        return self::SUPPORTED_TYPES;
    }

    private function assertValidType(string $type): void
    {
        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported media type: {$type}");
        }
    }
}
