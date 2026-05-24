<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Sermon;
use App\Services\SermonTranscriptReader;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LogSanitizationTest extends TestCase
{
    /**
     * Verify that Log Sanitization is applied to user-controlled or dynamic data
     * in core services. This test ensures the SanitizesLogData trait is correctly
     * integrated and used for path logging.
     */
    #[Test]
    public function it_sanitizes_sermon_transcript_reader_logs(): void
    {
        $service = app(SermonTranscriptReader::class);
        $maliciousPath = "private/../unsafe\npath.md";

        // The SanitizesLogData::sanitizeForLog logic:
        // $withoutControlChars = str_replace(["\r", "\n", "\t"], ' ', $value);
        // return trim((string) preg_replace('/\s+/', ' ', $withoutControlChars));
        $sanitizedPath = 'private/../unsafe path.md';

        $sermon = Sermon::factory()->create([
            'transcript_file_path' => $maliciousPath
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($sanitizedPath) {
                return $message === 'Unsafe path detected in transcript path'
                    && $context['path'] === $sanitizedPath;
            });

        $service->read($sermon);
    }
}
