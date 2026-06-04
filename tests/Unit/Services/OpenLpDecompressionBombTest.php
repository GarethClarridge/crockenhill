<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Song\OpenLpServiceParser;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class OpenLpDecompressionBombTest extends TestCase
{
    private OpenLpServiceParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new OpenLpServiceParser;
    }

    #[Test]
    public function it_rejects_archives_with_too_many_entries(): void
    {
        $upload = OpenLpArchiveFactory::makeUploadWithManyEntries(entryCount: 101);

        $this->expectException(ValidationException::class);

        $this->parser->parse($upload);
    }

    #[Test]
    public function it_accepts_archives_at_the_entry_count_limit(): void
    {
        // An archive with exactly max_zip_entries entries (100 + 1 osj = 101 total),
        // but the limit is on numFiles > max, so 100 dummy + 1 osj = 101 total > 100 → rejects.
        // An archive with 99 dummy entries + 1 osj = 100 total should pass the guard.
        $upload = OpenLpArchiveFactory::makeUploadWithManyEntries(entryCount: 99);

        // Should not throw the entry-count exception
        // (may still throw for other reasons, so we only assert it's not the entry-count message)
        try {
            $result = $this->parser->parse($upload);
            $this->assertNotNull($result, 'Parsing an archive at the entry-count limit should return a result.');
        } catch (ValidationException $e) {
            $this->assertStringNotContainsString(
                'too many entries',
                implode(' ', $e->errors()['file'] ?? [])
            );
        }
    }

    #[Test]
    public function it_rejects_osj_files_with_excessive_decompressed_size(): void
    {
        // Lower the threshold to 10 bytes so any real payload triggers the guard
        config(['service-tracking.upload.max_osj_decompressed_bytes' => 10]);

        $upload = OpenLpArchiveFactory::makeUpload();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds the maximum allowed size when decompressed');

        $this->parser->parse($upload);
    }

    #[Test]
    public function it_rejects_osj_files_with_suspicious_expansion_ratio(): void
    {
        // Lower the ratio threshold to 1.0 so any content with any compression triggers the guard.
        // Any compressed payload where decompressed size > compressed size has ratio > 1.
        config(['service-tracking.upload.max_expansion_ratio' => 1]);

        $upload = OpenLpArchiveFactory::makeUpload();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('suspicious compression ratio');

        $this->parser->parse($upload);
    }
}
