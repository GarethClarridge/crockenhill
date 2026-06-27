<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Enums\MediaType;
use App\Enums\SermonService;
use App\Enums\SermonSourceType;
use App\Exceptions\InvalidFileException;
use App\Models\MediaProcessingLog;
use App\Models\Sermon;
use App\Services\Media\TempDiskSpace;
use App\Services\Public\SermonRepository;
use App\Traits\SanitizesLogData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;

class SermonValidationService
{
    use SanitizesLogData;

    public function __construct(
        private readonly MediaValidationService $mediaValidation,
        private readonly SermonRepository $sermonRepository,
    ) {}

    /**
     * Validate the uploaded audio file against configured size and type constraints.
     *
     * @param  UploadedFile  $file  The uploaded audio file
     *
     * @throws InvalidFileException If the file is invalid
     */
    public function validateAudioFile(UploadedFile $file): void
    {
        $this->mediaValidation->validateUploadedFile(MediaType::Audio, $file);
    }

    /**
     * Validate processing metadata before starting the pipeline.
     *
     * @param  array{
     *     source_type: string,
     *     original_filename: string,
     * }  $metadata  Initial processing metadata
     * @return array<int, string> List of validation error messages
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
     * Generate fallback data for graceful degradation.
     *
     * Provides a set of minimum viable sermon attributes extracted from
     * existing records or filenames when AI analysis fails or is unavailable.
     *
     * @param  Sermon  $sermon  The sermon model (potentially partially populated)
     * @param  MediaProcessingLog  $processingLog  The active processing log
     * @return array{
     *     title: string,
     *     slug: string,
     *     series: null,
     *     reference: null,
     *     points: list<string>,
     * } Fallback attribute set
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
     * Generate a fallback title for graceful degradation.
     *
     * Attempts to derive a title from the original filename (stripping dates
     * and separators) or falls back to a date-based "Sermon - [Date]" format.
     *
     * @param  Sermon  $sermon  The sermon model
     * @param  MediaProcessingLog  $processingLog  The active processing log
     * @return string The generated title
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
     * Validate sermon data before creation/update.
     *
     * Applies standard sermon validation rules to an attribute array,
     * ensuring title, date, and other optional fields meet length and format
     * constraints.
     *
     * @param  array{
     *     sermon_id?: int,
     *     title?: string,
     *     date?: string,
     *     service?: string,
     *     preacher?: string,
     *     series?: string,
     *     reference?: string,
     *     slug?: string,
     * }  $data  The attribute array to validate
     * @return array<int, string> List of validation error messages
     */
    public function validateSermonData(array $data): array
    {
        /** @var Sermon|null $sermon */
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
            'service.'.Enum::class => 'Invalid service type. Must be one of: '.implode(', ', SermonService::values()),
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
     * Validate file storage constraints.
     *
     * Verifies sufficient disk space on the local temp disk and checks
     * for known file extension compatibility issues.
     *
     * @param  UploadedFile  $file  The file to evaluate
     * @return array<int, string> List of storage-related error messages or warnings
     */
    public function validateStorageConstraints(UploadedFile $file): array
    {
        $errors = [];

        // Check available space on the LOCAL temp disk — that is the disk uploads and
        // intermediate processing artefacts actually consume. The sermon disk is the
        // Spaces (S3) disk in production with no `root`, so the old check there silently
        // passed (disk_free_space(null)). TempDiskSpace reads the same threshold the
        // historic importer guard uses, so the two never disagree.
        //
        // Rationale: We require 2x the file size to provide headroom for the original
        // upload plus temporary processing artifacts generated during extraction
        // or compression.
        $requiredSpace = $file->getSize() * 2; // File + processing overhead

        try {
            if (! TempDiskSpace::hasSpaceFor($requiredSpace)) {
                $errors[] = 'Insufficient disk space for processing';
            }
        } catch (\Exception $e) {
            // If we can't check disk space, log but don't fail
            Log::warning('Could not check disk space', $this->sanitizeArrayForLog([
                'disk' => config('media-processing.storage.temp_disk', 'local'),
                'error' => $e->getMessage(),
            ]));
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
     * Validate application-level processing requirements.
     *
     * Checks if external services (like OpenAI), queues, and storage disks
     * are correctly configured before attempting to initiate processing.
     *
     * @return array<int, string> List of missing configuration error messages
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
     * Check if a failed processing run can be retried automatically.
     *
     * Evaluates the age of the run and the nature of the error (e.g. ignoring
     * non-retryable critical failures like 'file_not_found') to determine
     * if an automated retry attempt is appropriate.
     *
     * @param  MediaProcessingLog  $processingLog  The log to evaluate
     * @return bool True if the run is eligible for retry
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
     * Check if a processing run requires manual administrative review.
     *
     * Determines if a run has stalled in a critical step or encountered
     * an error pattern that cannot be resolved automatically (e.g. database
     * constraint violations or missing source files).
     *
     * @param  MediaProcessingLog  $processingLog  The log to evaluate
     * @return bool True if manual intervention is required
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
