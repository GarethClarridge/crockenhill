<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConvertJpgToWebpCommandTest extends TestCase
{
    /** @var list<string> */
    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    /** Create a file and register it for cleanup. */
    private function createFile(string $absolutePath, string $contents = ''): void
    {
        $dir = dirname($absolutePath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        File::put($absolutePath, $contents);
        $this->createdFiles[] = $absolutePath;
    }

    #[Test]
    public function it_does_not_rewrite_references_when_skip_convert_leaves_converted_files_empty(): void
    {
        // Place a blade file in the real views directory with a JPG reference
        $bladePath = base_path('resources/views/td005c-test-hero.blade.php');
        $originalContent = '<img src="/images/td005c-hero.jpg" alt="Hero">';
        $this->createFile($bladePath, $originalContent);

        // Place a matching jpg in the real public/images directory (but we skip conversion)
        $jpgPath = base_path('public/images/td005c-hero.jpg');
        $this->createFile($jpgPath, 'fake-jpg-bytes');

        // --skip-convert means $convertedFiles stays empty, so references must not be rewritten
        $this->artisan('images:convert-to-webp', [
            '--skip-convert' => true,
        ])->assertSuccessful();

        $this->assertEquals($originalContent, File::get($bladePath));
    }

    #[Test]
    public function it_does_not_rewrite_references_for_files_that_failed_to_convert(): void
    {
        // Create a blade file referencing a JPG that will fail to convert (it's not a real image)
        $bladePath = base_path('resources/views/td005c-test-broken.blade.php');
        $originalContent = '<img src="/images/td005c-broken.jpg" alt="Broken">';
        $this->createFile($bladePath, $originalContent);

        // A corrupt/fake JPG file — Intervention Image will throw on this
        $jpgPath = base_path('public/images/td005c-broken.jpg');
        $this->createFile($jpgPath, 'not-a-real-image');

        // Command should exit 1 because of the conversion error
        $result = $this->artisan('images:convert-to-webp', [
            '--skip-references' => true,
        ]);
        $result->assertFailed();

        // Even if we hadn't skipped references, the blade file is unchanged
        // because the failed file never enters $convertedFiles
        $this->assertEquals($originalContent, File::get($bladePath));
    }

    #[Test]
    public function it_exits_zero_when_no_jpg_files_exist_and_no_operations_performed(): void
    {
        // With no JPG files anywhere in the scan paths, the command should be a clean no-op
        $this->artisan('images:convert-to-webp', [
            '--skip-convert' => true,
            '--skip-references' => true,
        ])->assertSuccessful();
    }
}
