<?php

declare(strict_types=1);

namespace App\Data;

/**
 * ProcessingConfiguration - Value object for processing configuration
 *
 * Part of Phase 5 refactoring to reduce method complexity and improve maintainability.
 * Encapsulates processing configuration data with type safety.
 */
class ProcessingConfiguration
{
    /**
     * @param  array<int, string>  $allowedExtensions
     * @param  array<string, mixed>  $validationRules
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly array $allowedExtensions,
        public readonly int $maxFileSize,
        public readonly string $description,
        public readonly array $validationRules,
        public readonly string $queue = 'default',
        public readonly int $timeout = 3600,
        public readonly array $metadata = []
    ) {}

    /**
     * Create configuration for audio processing
     */
    public static function forAudio(): self
    {
        return new self(
            allowedExtensions: ['mp3', 'wav', 'm4a', 'mp4'],
            maxFileSize: config('media-processing.types.audio.max_file_size', 104857600), // 100MB
            description: 'Audio sermon file processing',
            validationRules: [
                'file_required' => true,
                'mime_types' => ['audio/mpeg', 'audio/wav', 'audio/mp4', 'audio/x-m4a'],
                'min_duration' => 60, // seconds
                'max_duration' => 7200, // 2 hours
            ],
            queue: 'audio-processing',
            timeout: 1800, // 30 minutes
            metadata: [
                'processing_type' => 'audio',
                'supports_streaming' => false,
                'requires_segmentation' => false,
            ]
        );
    }

    /**
     * Create configuration for direct video processing
     */
    public static function forDirectVideo(): self
    {
        return new self(
            allowedExtensions: ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            maxFileSize: config('media-processing.types.video.max_file_size', 1073741824), // 1GB
            description: 'Direct sermon video file processing',
            validationRules: [
                'file_required' => true,
                'mime_types' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'],
                'min_duration' => 300, // 5 minutes
                'max_duration' => 7200, // 2 hours
                'max_resolution' => '1920x1080',
            ],
            queue: 'video-processing',
            timeout: 3600, // 1 hour
            metadata: [
                'processing_type' => 'sermon_video',
                'supports_streaming' => false,
                'requires_segmentation' => false,
                'generates_thumbnail' => true,
            ]
        );
    }

    /**
     * Create configuration for livestream processing
     */
    public static function forLivestream(): self
    {
        return new self(
            allowedExtensions: ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            maxFileSize: config('media-processing.types.livestream.max_file_size', 2147483648), // 2GB
            description: 'Full livestream recording requiring segmentation',
            validationRules: [
                'file_required' => true,
                'mime_types' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'],
                'min_duration' => 1800, // 30 minutes
                'max_duration' => 14400, // 4 hours
            ],
            queue: 'livestream-processing',
            timeout: 7200, // 2 hours
            metadata: [
                'processing_type' => 'livestream',
                'supports_streaming' => true,
                'requires_segmentation' => true,
                'generates_thumbnail' => true,
                'extracts_sermon' => true,
            ]
        );
    }

    /**
     * Check if file extension is allowed
     */
    public function isExtensionAllowed(string $extension): bool
    {
        return in_array(strtolower($extension), $this->allowedExtensions);
    }

    /**
     * Check if file size is within limits
     */
    public function isFileSizeValid(int $size): bool
    {
        return $size <= $this->maxFileSize;
    }

    /**
     * Check if MIME type is allowed
     */
    public function isMimeTypeAllowed(string $mimeType): bool
    {
        return in_array($mimeType, $this->validationRules['mime_types'] ?? []);
    }

    /**
     * Get maximum file size in human readable format
     */
    public function getMaxFileSizeFormatted(): string
    {
        $bytes = $this->maxFileSize;

        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    /**
     * Get allowed extensions as formatted string
     */
    public function getAllowedExtensionsFormatted(): string
    {
        return implode(', ', $this->allowedExtensions);
    }

    /**
     * Convert to array for API responses
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->metadata['processing_type'] ?? 'unknown',
            'description' => $this->description,
            'allowed_extensions' => $this->allowedExtensions,
            'max_file_size' => $this->maxFileSize,
            'max_file_size_formatted' => $this->getMaxFileSizeFormatted(),
            'queue' => $this->queue,
            'timeout' => $this->timeout,
            'validation_rules' => $this->validationRules,
            'metadata' => $this->metadata,
        ];
    }
}
