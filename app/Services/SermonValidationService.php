<?php

namespace App\Services;

use App\Exceptions\InvalidFileException;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class SermonValidationService
{
    /**
     * Validate the uploaded audio file
     */
    public function validateAudioFile(UploadedFile $file): void
    {
        // Check file size
        $maxSize = config('media-processing.processing.max_file_size', 100 * 1024 * 1024);
        if ($file->getSize() > $maxSize) {
            $maxSizeMB = round($maxSize / (1024 * 1024));
            throw new InvalidFileException(["File size exceeds maximum limit of {$maxSizeMB}MB"]);
        }

        // Check MIME type
        $allowedMimeTypes = config('media-processing.processing.allowed_mime_types', [
            'audio/mpeg',
            'audio/mp3',
            'audio/wav',
            'audio/x-wav',
            'audio/mp4',
            'audio/m4a',
        ]);

        if (! in_array($file->getMimeType(), $allowedMimeTypes)) {
            throw new InvalidFileException(['Invalid file type. Only audio files are allowed.']);
        }

        // Check file extension
        $allowedExtensions = config('media-processing.processing.allowed_extensions', ['mp3', 'wav', 'm4a', 'mp4']);
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, $allowedExtensions)) {
            throw new InvalidFileException(['Invalid file extension. Allowed: '.implode(', ', $allowedExtensions)]);
        }

        // Basic file integrity check
        if (! $file->isValid()) {
            throw new InvalidFileException(['Uploaded file is corrupted or invalid']);
        }
    }

    /**
     * Validate processing metadata
     */
    public function validateProcessingMetadata(array $metadata): array
    {
        $errors = [];

        // Validate required fields
        if (empty($metadata['source_type'])) {
            $errors[] = 'Source type is required';
        }

        if (empty($metadata['original_filename'])) {
            $errors[] = 'Original filename is required';
        }

        // Validate source type
        $validSourceTypes = ['audio_upload', 'video_upload', 'livestream'];
        if (! empty($metadata['source_type']) && ! in_array($metadata['source_type'], $validSourceTypes)) {
            $errors[] = 'Invalid source type. Must be one of: '.implode(', ', $validSourceTypes);
        }

        // Validate filename format if provided
        if (! empty($metadata['original_filename'])) {
            $filename = $metadata['original_filename'];

            // Check for potentially dangerous file patterns
            if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
                $errors[] = 'Filename contains invalid characters';
            }

            // Check filename length
            if (strlen($filename) > 255) {
                $errors[] = 'Filename too long (maximum 255 characters)';
            }
        }

        return $errors;
    }

    /**
     * Generate fallback data for graceful degradation
     */
    public function generateFallbackData(Sermon $sermon, MediaProcessingLog $processingLog): array
    {
        // Generate basic title if current title is generic
        $title = $sermon->title;
        if (
            empty($title) ||
            str_contains(strtolower($title), 'untitled') ||
            str_contains(strtolower($title), 'sermon -')
        ) {
            $title = $this->generateFallbackTitle($sermon, $processingLog);
        }

        // Generate unique slug
        $slug = $this->generateUniqueSlug($title, $sermon->id);

        return [
            'title' => $title,
            'slug' => $slug,
            'series' => null, // No series identification in fallback
            'reference' => null, // No Bible passage extraction in fallback
            'points' => ['Main Message'], // Simple fallback points
        ];
    }

    /**
     * Generate a fallback title for graceful degradation
     */
    public function generateFallbackTitle(Sermon $sermon, MediaProcessingLog $processingLog): string
    {
        // Try to extract from original filename
        if (! empty($processingLog->original_filename)) {
            $filename = pathinfo($processingLog->original_filename, PATHINFO_FILENAME);
            $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename);
            $title = preg_replace('/[-_]+/', ' ', $title);
            $title = trim($title);

            if (! empty($title) && strlen($title) > 3) {
                return Str::title($title);
            }
        }

        // Fallback to date-based title
        $service = $sermon->service ? $sermon->service->value : '';

        return "Sermon - {$sermon->date->format('F j, Y')} {$service}";
    }

    /**
     * Generate a unique slug, excluding the current sermon
     */
    public function generateUniqueSlug(string $title, int $excludeSermonId): string
    {
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        // Ensure slug is unique (excluding current sermon)
        while (Sermon::where('slug', $slug)->where('id', '!=', $excludeSermonId)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Validate sermon data before creation/update
     */
    public function validateSermonData(array $data): array
    {
        $errors = [];

        // Title validation
        if (empty($data['title'])) {
            $errors[] = 'Sermon title is required';
        } elseif (strlen($data['title']) > 255) {
            $errors[] = 'Sermon title too long (maximum 255 characters)';
        }

        // Date validation
        if (empty($data['date'])) {
            $errors[] = 'Sermon date is required';
        } elseif (! strtotime($data['date'])) {
            $errors[] = 'Invalid sermon date format';
        }

        // Service validation
        if (! empty($data['service'])) {
            $validServices = ['morning', 'evening', 'other'];
            if (! in_array($data['service'], $validServices)) {
                $errors[] = 'Invalid service type. Must be one of: '.implode(', ', $validServices);
            }
        }

        // Preacher validation
        if (! empty($data['preacher']) && strlen($data['preacher']) > 100) {
            $errors[] = 'Preacher name too long (maximum 100 characters)';
        }

        // Series validation
        if (! empty($data['series']) && strlen($data['series']) > 100) {
            $errors[] = 'Series name too long (maximum 100 characters)';
        }

        // Reference validation
        if (! empty($data['reference']) && strlen($data['reference']) > 255) {
            $errors[] = 'Bible reference too long (maximum 255 characters)';
        }

        // Slug validation and uniqueness
        if (! empty($data['slug'])) {
            if (! preg_match('/^[a-z0-9\-]+$/', $data['slug'])) {
                $errors[] = 'Slug can only contain lowercase letters, numbers, and hyphens';
            }

            // Check slug uniqueness (if sermon ID provided, exclude it)
            $slugQuery = Sermon::where('slug', $data['slug']);
            if (! empty($data['sermon_id'])) {
                $slugQuery->where('id', '!=', $data['sermon_id']);
            }

            if ($slugQuery->exists()) {
                $errors[] = 'Slug already exists - must be unique';
            }
        }

        return $errors;
    }

    /**
     * Validate file storage constraints
     */
    public function validateStorageConstraints(UploadedFile $file): array
    {
        $errors = [];

        // Check available disk space
        $disk = config('media-processing.storage.sermon_disk', 'public');
        $requiredSpace = $file->getSize() * 2; // File + processing overhead

        try {
            $diskPath = config("filesystems.disks.{$disk}.root");
            if ($diskPath && disk_free_space($diskPath) < $requiredSpace) {
                $errors[] = 'Insufficient disk space for processing';
            }
        } catch (\Exception $e) {
            // If we can't check disk space, log but don't fail
            \Illuminate\Support\Facades\Log::warning('Could not check disk space', [
                'disk' => $disk,
                'error' => $e->getMessage(),
            ]);
        }

        // Check file format compatibility
        $extension = strtolower($file->getClientOriginalExtension());
        $compatibilityIssues = [
            'wma' => 'WMA files may have compatibility issues - consider converting to MP3',
            'flac' => 'FLAC files are large - consider MP3 for better processing performance',
            'aac' => 'AAC files may require additional processing time',
        ];

        if (isset($compatibilityIssues[$extension])) {
            $errors[] = $compatibilityIssues[$extension];
        }

        return $errors;
    }

    /**
     * Validate processing requirements
     */
    public function validateProcessingRequirements(): array
    {
        $errors = [];

        // Check required services are configured
        if (! config('services.openai.key') && config('media-processing.transcription.service') === 'openai') {
            $errors[] = 'OpenAI API key not configured but required for transcription';
        }

        // Check queue configuration
        if (! config('queue.default')) {
            $errors[] = 'Queue system not configured - required for processing jobs';
        }

        // Check storage configuration
        $disk = config('media-processing.storage.sermon_disk', 'public');
        if (! config("filesystems.disks.{$disk}")) {
            $errors[] = "Storage disk '{$disk}' not configured";
        }

        return $errors;
    }

    /**
     * Check if processing can be retried automatically
     */
    public function canRetryProcessing(MediaProcessingLog $processingLog): bool
    {
        // Don't retry if it's been marked for manual review
        if (str_contains($processingLog->current_step ?? '', 'manual_review')) {
            return false;
        }

        // Don't retry if it's too old (more than 7 days)
        if ($processingLog->created_at->diffInDays(now()) > 7) {
            return false;
        }

        // Don't retry certain critical failures
        $criticalFailures = [
            'file_not_found',
            'invalid_file_format',
            'storage_failure',
        ];

        foreach ($criticalFailures as $failure) {
            if (str_contains(strtolower($processingLog->error_message ?? ''), $failure)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if processing requires manual review
     */
    public function requiresManualReview(MediaProcessingLog $processingLog): bool
    {
        // Already marked for manual review
        if (str_contains($processingLog->current_step ?? '', 'manual_review')) {
            return true;
        }

        // Multiple failures in critical steps
        $criticalSteps = [
            'creating_sermon_record',
            'transcribing_audio',
        ];

        if (in_array($processingLog->current_step, $criticalSteps)) {
            return true;
        }

        // Check for specific error patterns that require manual intervention
        $manualReviewPatterns = [
            'file not found',
            'invalid audio format',
            'transcription service unavailable',
            'storage failure',
            'database constraint violation',
        ];

        $errorMessage = strtolower($processingLog->error_message ?? '');
        foreach ($manualReviewPatterns as $pattern) {
            if (str_contains($errorMessage, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
