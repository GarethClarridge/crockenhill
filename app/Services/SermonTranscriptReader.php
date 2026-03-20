<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Sermon;
use Illuminate\Support\Facades\Log;

class SermonTranscriptReader
{
    public function __construct(
        private readonly TranscriptStorageService $transcriptStorageService,
    ) {}

    public function read(Sermon $sermon): ?string
    {
        if (! $sermon->transcript_file_path) {
            return null;
        }

        $path = trim((string) $sermon->transcript_file_path);

        if (str_contains($path, '..')) {
            Log::warning('Path traversal attempt detected in transcript path', [
                'sermon_id' => $sermon->id,
                'path' => $path,
            ]);

            return null;
        }

        $transcript = $this->transcriptStorageService->readTranscriptFromPath($path);

        if ($transcript === null) {
            Log::warning('Transcript file not found on any configured disk', [
                'disks_checked' => $this->transcriptStorageService->getTranscriptReadDisks(),
                'sermon_id' => $sermon->id,
                'transcript_file_path' => $path,
            ]);
        }

        return $transcript;
    }
}
