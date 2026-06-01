<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MediaType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Repositories\SermonRepository;
use App\Traits\SanitizesLogData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SermonValidationService
{
    use SanitizesLogData;

    public function __construct(
        private readonly MediaValidationService $mediaValidation,
        private readonly SermonRepository $sermonRepository,
    ) {}

    /**
     * Validate the uploaded audio file
     */
    public function validateAudioFile(UploadedFile $file): void
    {
        $this->mediaValidation->validateUploadedFile(MediaType::Audio, $file);
    }

    /**
     * Validate processing metadata
     *
     * @param  array<string, mixed>  $metadata
     * @return array<int, string>
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
        $validSourceTypeValues = array_column(SermonSourceType::cases(), 'value');
        if (! empty($metadata['source_type']) && ! in_array($metadata['source_type'], $validSourceTypeValues)) {
            $errors[] = 'Invalid source type. Must be one of: '.implode(', ', $validSourceTypeValues);
        }

        // Validate filename format if provided
        if (! empty($metadata['original_filename'])) {
            $filename = $metadata['original_filename'];

            // Check for potentially dangerous file patterns
            if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, '://')) {
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
     *
     * @return array<string, mixed>
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
        $slug = $this->sermonRepository->generateUniqueSlug($title, $sermon->id);

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
            $title = preg_replace('/\d{4}[-_]\d{1,2}[-_]\d{1,2}/', '', $filename) ?? $filename;
            $title = preg_replace('/[-_]+/', ' ', $title) ?? $title;
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
     * Validate sermon data before creation/update
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public function validateSermonData(array $data): array
    {
        $sermon = isset($data['sermon_id']) ? Sermon::query()->find($data['sermon_id']) : null;
        $rules = Sermon::validationRules($sermon);

        // Date validation is not in model rules as it's often inferred
        $rules['date'] = ['required', 'date'];

        // Filter rules to only include what's in the data or required
        $activeRules = array_intersect_key($rules, $data);
        if (! isset($data['title'])) {
            $activeRules['title'] = $rules['title'];
        }
        if (! isset($data['date'])) {
            $activeRules['date'] = $rules['date'];
        }

        $validator = Validator::make($data, $activeRules, [
            'title.required' => 'Sermon title is required',
            'title.max' => 'Sermon title too long (maximum 255 characters)',
            'date.required' => 'Sermon date is required',
            'date.date' => 'Invalid sermon date format',
            'service.'.\Illuminate\Validation\Rules\Enum::class => 'Invalid service type. Must be one of: '.implode(', ', SermonService::values()),
            'preacher.max' => 'Preacher name too long (maximum 255 characters)',
            'series.max' => 'Series name too long (maximum 255 characters)',
            'reference.max' => 'Bible reference too long (maximum 255 characters)',
            'slug.regex' => 'Slug can only contain lowercase letters, numbers, and hyphens',
            'slug.unique' => 'Slug already exists - must be unique',
        ]);

        /** @var array<int, string> $errors */
        $errors = $validator->errors()->all();

        return $errors;
    }

    /**
     * Validate file storage constraints
     *
     * @return array<int, string>
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
            Log::warning('Could not check disk space', [
                'disk' => $this->sanitizeForLog($disk),
                'error' => $this->sanitizeForLog($e->getMessage()),
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
     *
     * @return array<int, string>
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
        if ($processingLog->created_at !== null && $processingLog->created_at->diffInDays(now()) > 7) {
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
