<?php

declare(strict_types=1);

namespace App\Services;

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
