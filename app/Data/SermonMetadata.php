<?php

namespace App\Data;

use App\Enums\SermonService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

class SermonMetadata extends Data
{
    public function __construct(
        #[Required]
        public readonly Carbon $date,

        #[Required]
        public readonly SermonService $service,

        #[Required]
        #[Max(255)]
        public readonly string $filename,

        #[Required]
        #[Max(255)]
        public readonly string $originalName,

        public readonly ?float $duration = null,
        public readonly ?int $bitrate = null,
        public readonly ?string $format = null,
        public readonly ?int $filesize = null,
    ) {}

    /**
     * Create SermonMetadata from an UploadedFile
     */
    public static function fromUploadedFile(UploadedFile $file): self
    {
        $originalName = $file->getClientOriginalName();
        $filename = $file->hashName();

        // Extract date from filename (assuming format like "2024-01-15_sermon.mp3" or "15-01-2024_sermon.mp3")
        $date = self::extractDateFromFilename($originalName);

        // Determine service type from file creation time or default to morning
        $service = self::determineServiceFromFile($file);

        return new self(
            date: $date,
            service: $service,
            filename: $filename,
            originalName: $originalName
        );
    }

    /**
     * Extract date from filename using various patterns
     */
    private static function extractDateFromFilename(string $filename): Carbon
    {
        // Remove file extension for easier parsing
        $nameWithoutExtension = pathinfo($filename, PATHINFO_FILENAME);

        // Pattern 1: YYYY-MM-DD format
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $nameWithoutExtension, $matches)) {
            return Carbon::createFromDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        // Pattern 2: DD-MM-YYYY format
        if (preg_match('/(\d{1,2})-(\d{1,2})-(\d{4})/', $nameWithoutExtension, $matches)) {
            return Carbon::createFromDate((int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        // Pattern 3: YYYY_MM_DD format
        if (preg_match('/(\d{4})_(\d{1,2})_(\d{1,2})/', $nameWithoutExtension, $matches)) {
            return Carbon::createFromDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        // Pattern 4: DD_MM_YYYY format
        if (preg_match('/(\d{1,2})_(\d{1,2})_(\d{4})/', $nameWithoutExtension, $matches)) {
            return Carbon::createFromDate((int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        // Fallback: use current date
        return Carbon::today();
    }

    /**
     * Determine service type from file creation time
     * Morning service if created before 2 PM, evening service otherwise
     */
    private static function determineServiceFromFile(UploadedFile $file): SermonService
    {
        try {
            // Get file creation time from the uploaded file
            $filePath = $file->getRealPath();
            if ($filePath && file_exists($filePath)) {
                $creationTime = Carbon::createFromTimestamp(filectime($filePath));

                // If created before 2 PM, assume morning service
                if ($creationTime->hour < 14) {
                    return SermonService::MORNING;
                } else {
                    return SermonService::EVENING;
                }
            }
        } catch (\Exception $e) {
            // If we can't determine from file time, fall back to default
        }

        // Default to morning service
        return SermonService::MORNING;
    }

    /**
     * Create SermonMetadata with explicit values (for testing or manual creation)
     */
    public static function create(
        Carbon $date,
        SermonService $service,
        string $filename,
        string $originalName,
        ?float $duration = null,
        ?int $bitrate = null,
        ?string $format = null,
        ?int $filesize = null
    ): self {
        return new self(
            date: $date,
            service: $service,
            filename: $filename,
            originalName: $originalName,
            duration: $duration,
            bitrate: $bitrate,
            format: $format,
            filesize: $filesize
        );
    }

    /**
     * Return a new instance with the specified original name
     */
    public function withOriginalName(string $originalName): self
    {
        return new self(
            date: $this->date,
            service: $this->service,
            filename: $this->filename,
            originalName: $originalName,
            duration: $this->duration,
            bitrate: $this->bitrate,
            format: $this->format,
            filesize: $this->filesize
        );
    }
}
