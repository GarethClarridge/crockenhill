<?php

namespace Tests\Unit\Services;

use App\Services\MediaValidationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MediaValidationServiceTest extends TestCase
{
    private MediaValidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MediaValidationService;
    }

    #[Test]
    public function it_returns_rules_for_audio(): void
    {
        $rules = $this->service->rulesForType('audio');

        $this->assertArrayHasKey('file', $rules);
        $this->assertStringContainsString('required', $rules['file']);
        $this->assertStringContainsString('file', $rules['file']);
        $this->assertStringContainsString('mimes:mp3,wav,m4a,mp4', $rules['file']);

        // 100MB = 102400 KB
        $this->assertStringContainsString('max:102400', $rules['file']);
    }

    #[Test]
    public function it_returns_rules_for_video(): void
    {
        $rules = $this->service->rulesForType('video');

        $this->assertArrayHasKey('file', $rules);
        $this->assertStringContainsString('mimes:mp4,mov,avi,mkv', $rules['file']);

        // 1GB = 1048576 KB
        $this->assertStringContainsString('max:1048576', $rules['file']);
    }

    #[Test]
    public function it_returns_rules_for_livestream(): void
    {
        $rules = $this->service->rulesForType('livestream');

        $this->assertArrayHasKey('file', $rules);
        $this->assertStringContainsString('mimes:mp4,mov,avi,mkv,webm', $rules['file']);

        // 2GB = 2097152 KB
        $this->assertStringContainsString('max:2097152', $rules['file']);
    }

    #[Test]
    public function it_throws_for_invalid_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported media type: invalid');

        $this->service->rulesForType('invalid');
    }

    #[Test]
    public function it_returns_human_readable_size_for_audio(): void
    {
        $this->assertEquals('100MB', $this->service->maxFileSizeForDisplay('audio'));
    }

    #[Test]
    public function it_returns_human_readable_size_for_video(): void
    {
        $this->assertEquals('1GB', $this->service->maxFileSizeForDisplay('video'));
    }

    #[Test]
    public function it_returns_human_readable_size_for_livestream(): void
    {
        $this->assertEquals('2GB', $this->service->maxFileSizeForDisplay('livestream'));
    }

    #[Test]
    public function it_returns_allowed_extensions_for_display(): void
    {
        $this->assertEquals('MP3, WAV, M4A, MP4', $this->service->allowedExtensionsForDisplay('audio'));
        $this->assertEquals('MP4, MOV, AVI, MKV', $this->service->allowedExtensionsForDisplay('video'));
        $this->assertEquals('MP4, MOV, AVI, MKV, WEBM', $this->service->allowedExtensionsForDisplay('livestream'));
    }

    #[Test]
    public function it_returns_accept_attribute_for_html_input(): void
    {
        $this->assertEquals('.mp3,.wav,.m4a,.mp4', $this->service->acceptAttribute('audio'));
        $this->assertEquals('.mp4,.mov,.avi,.mkv', $this->service->acceptAttribute('video'));
        $this->assertEquals('.mp4,.mov,.avi,.mkv,.webm', $this->service->acceptAttribute('livestream'));
    }

    #[Test]
    public function it_returns_max_file_size_in_bytes(): void
    {
        $this->assertEquals(100 * 1024 * 1024, $this->service->maxFileSizeBytes('audio'));
        $this->assertEquals(1024 * 1024 * 1024, $this->service->maxFileSizeBytes('video'));
        $this->assertEquals(2 * 1024 * 1024 * 1024, $this->service->maxFileSizeBytes('livestream'));
    }

    #[Test]
    public function it_returns_allowed_extensions_raw_values(): void
    {
        $this->assertEquals(['mp3', 'wav', 'm4a', 'mp4'], $this->service->allowedExtensions('audio'));
        $this->assertEquals(['mp4', 'mov', 'avi', 'mkv'], $this->service->allowedExtensions('video'));
        $this->assertEquals(['mp4', 'mov', 'avi', 'mkv', 'webm'], $this->service->allowedExtensions('livestream'));
    }

    #[Test]
    public function it_returns_allowed_mime_types_raw_values(): void
    {
        $this->assertEquals(
            ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/m4a'],
            $this->service->allowedMimes('audio')
        );
        $this->assertEquals(
            ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'],
            $this->service->allowedMimes('video')
        );
        $this->assertEquals(
            ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm'],
            $this->service->allowedMimes('livestream')
        );
    }

    #[Test]
    public function it_returns_supported_types(): void
    {
        $this->assertEquals(['audio', 'video', 'livestream'], $this->service->supportedTypes());
    }

    // ---- validateUploadedFile ----

    #[Test]
    public function validate_uploaded_file_passes_for_valid_audio(): void
    {
        $file = $this->createMockUploadedFile('sermon.mp3', 'audio/mpeg', 1 * 1024 * 1024);

        // Should not throw
        $this->service->validateUploadedFile('audio', $file);
        $this->assertTrue(true);
    }

    #[Test]
    public function validate_uploaded_file_throws_for_oversized_audio(): void
    {
        $file = $this->createMockUploadedFile('sermon.mp3', 'audio/mpeg', 200 * 1024 * 1024);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File size exceeds maximum limit');

        $this->service->validateUploadedFile('audio', $file);
    }

    #[Test]
    public function validate_uploaded_file_throws_for_invalid_mime(): void
    {
        $file = $this->createMockUploadedFile('sermon.exe', 'application/octet-stream', 1 * 1024 * 1024);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid file type');

        $this->service->validateUploadedFile('audio', $file);
    }

    #[Test]
    public function validate_uploaded_file_throws_for_invalid_extension(): void
    {
        $file = $this->createMockUploadedFile('sermon.flac', 'audio/mpeg', 1 * 1024 * 1024);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid file extension');

        $this->service->validateUploadedFile('audio', $file);
    }

    #[Test]
    public function validate_uploaded_file_throws_for_invalid_type_argument(): void
    {
        $file = $this->createMockUploadedFile('sermon.mp3', 'audio/mpeg', 1 * 1024 * 1024);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported media type');

        $this->service->validateUploadedFile('podcast', $file);
    }

    /**
     * Create a partial mock UploadedFile with controlled size/mime/extension/validity.
     */
    private function createMockUploadedFile(string $name, string $mimeType, int $size): \Illuminate\Http\UploadedFile
    {
        $file = $this->createMock(\Illuminate\Http\UploadedFile::class);
        $file->method('isValid')->willReturn(true);
        $file->method('getSize')->willReturn($size);
        $file->method('getMimeType')->willReturn($mimeType);
        $file->method('getClientOriginalExtension')->willReturn(pathinfo($name, PATHINFO_EXTENSION));

        return $file;
    }

    #[Test]
    public function all_types_produce_consistent_rules_from_config(): void
    {
        foreach ($this->service->supportedTypes() as $type) {
            $rules = $this->service->rulesForType($type);
            $config = config("media-processing.types.{$type}");

            $expectedMaxKB = (int) ($config['max_file_size'] / 1024);
            $expectedExtensions = implode(',', $config['allowed_extensions']);

            $this->assertStringContainsString("max:{$expectedMaxKB}", $rules['file'],
                "Max size mismatch for {$type}");
            $this->assertStringContainsString("mimes:{$expectedExtensions}", $rules['file'],
                "Extensions mismatch for {$type}");
        }
    }
}
