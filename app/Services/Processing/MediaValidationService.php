<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\MediaType;
use App\Exceptions\InvalidFileException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Number;

class MediaValidationService
{
    /**
     * Return Laravel validation rules for a given media type.
     *
     * @return array{file: string}
     */
    public function rulesForType(MediaType $type): array
    {
        $config = config("media-processing.types.{$type->value}");
        $maxSizeKB = (int) ($config['max_file_size'] / 1024);
        $extensions = implode(',', $config['allowed_extensions']);

        return [
            'file' => "required|file|mimes:{$extensions}|max:{$maxSizeKB}",
        ];
    }

    /**
     * Return human-readable max file size string for display.
     */
    public function maxFileSizeForDisplay(MediaType $type): string
    {
        $bytes = config("media-processing.types.{$type->value}.max_file_size");

        return Number::fileSize($bytes, precision: 2);
    }

    /**
     * Return comma-separated list of allowed extensions for display.
     */
    public function allowedExtensionsForDisplay(MediaType $type): string
    {
        $extensions = config("media-processing.types.{$type->value}.allowed_extensions");

        return implode(', ', array_map('strtoupper', $extensions));
    }

    /**
     * Return the accept attribute value for an HTML file input.
     */
    public function acceptAttribute(MediaType $type): string
    {
        $extensions = config("media-processing.types.{$type->value}.allowed_extensions");

        return implode(',', array_map(fn (string $ext) => ".{$ext}", $extensions));
    }

    /**
     * Return the max file size in bytes for a given type.
     */
    public function maxFileSizeBytes(MediaType $type): int
    {
        return (int) config("media-processing.types.{$type->value}.max_file_size");
    }

    /**
     * @return array<string>
     */
    public function allowedExtensions(MediaType $type): array
    {
        return config("media-processing.types.{$type->value}.allowed_extensions", []);
    }

    /**
     * @return array<string>
     */
    public function allowedMimes(MediaType $type): array
    {
        return config("media-processing.types.{$type->value}.allowed_mimes", []);
    }

    /**
     * Validate a local file path against the canonical rules for a given media type.
     *
     * Throws \App\Exceptions\InvalidFileException on the first failing constraint.
     */
    public function validateLocalFile(MediaType $type, string $filePath): void
    {
        $maxSize = $this->maxFileSizeBytes($type);
        $fileSize = filesize($filePath);

        if ($fileSize === false || $fileSize > $maxSize) {
            $maxSizeDisplay = $this->maxFileSizeForDisplay($type);
            throw new InvalidFileException(["File size exceeds maximum limit of {$maxSizeDisplay}"]);
        }

        $mimeType = mime_content_type($filePath);
        if ($mimeType === false || ! in_array($mimeType, $this->allowedMimes($type), true)) {
            $display = $this->allowedExtensionsForDisplay($type);
            throw new InvalidFileException(["Invalid file type. Supported formats: {$display}."]);
        }
    }

    /**
     * Validate an uploaded file against the canonical rules for a given media type.
     *
     * Throws \App\Exceptions\InvalidFileException on the first failing constraint.
     */
    public function validateUploadedFile(MediaType $type, UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new InvalidFileException(['Uploaded file is corrupted or invalid']);
        }

        $maxSize = $this->maxFileSizeBytes($type);
        if ($file->getSize() > $maxSize) {
            $maxSizeDisplay = $this->maxFileSizeForDisplay($type);
            throw new InvalidFileException(["File size exceeds maximum limit of {$maxSizeDisplay}"]);
        }

        $allowedMimes = $this->allowedMimes($type);
        if (! in_array($file->getMimeType(), $allowedMimes, true)) {
            $display = $this->allowedExtensionsForDisplay($type);
            throw new InvalidFileException(["Invalid file type. Supported formats: {$display}."]);
        }

        $allowedExtensions = $this->allowedExtensions($type);
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, $allowedExtensions, true)) {
            throw new InvalidFileException(['Invalid file extension. Allowed: '.implode(', ', $allowedExtensions)]);
        }
    }

    /**
     * @return array<MediaType>
     */
    public function supportedTypes(): array
    {
        return MediaType::cases();
    }
}
