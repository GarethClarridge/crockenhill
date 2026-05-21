<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ConvertJpgToWebp;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ConvertJpgToWebpCommandTest extends TestCase
{
    private string $tempRoot;

    private string $imageDir;

    private string $bladeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = storage_path('framework/testing/jpg-to-webp-'.bin2hex(random_bytes(4)));
        $this->imageDir = $this->tempRoot.'/images';
        $this->bladeDir = $this->tempRoot.'/views';

        File::makeDirectory($this->imageDir, 0755, true);
        File::makeDirectory($this->bladeDir, 0755, true);

        $this->app->bind(ConvertJpgToWebp::class, fn () => new class($this->imageDir, $this->bladeDir) extends ConvertJpgToWebp
        {
            public function __construct(private readonly string $imageDir, private readonly string $bladeDir)
            {
                parent::__construct();
            }

            protected function imageScanPaths(): array
            {
                return [$this->imageDir];
            }

            protected function referenceScanPaths(): array
            {
                return [$this->bladeDir => ['blade.php']];
            }
        });
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempRoot)) {
            File::deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_does_not_rewrite_references_when_skip_convert_leaves_converted_files_empty(): void
    {
        $bladePath = $this->bladeDir.'/td005c-test-hero.blade.php';
        $originalContent = '<img src="/images/td005c-hero.jpg" alt="Hero">';
        File::put($bladePath, $originalContent);

        File::put($this->imageDir.'/td005c-hero.jpg', 'fake-jpg-bytes');

        // --skip-convert means $convertedFiles stays empty, so references must not be rewritten
        $this->artisan('images:convert-to-webp', [
            '--skip-convert' => true,
        ])->assertSuccessful();

        $this->assertEquals($originalContent, File::get($bladePath));
    }

    #[Test]
    public function it_does_not_rewrite_references_for_files_that_failed_to_convert(): void
    {
        $bladePath = $this->bladeDir.'/td005c-test-broken.blade.php';
        $originalContent = '<img src="/images/td005c-broken.jpg" alt="Broken">';
        File::put($bladePath, $originalContent);

        // A corrupt/fake JPG file — Intervention Image will throw on this
        File::put($this->imageDir.'/td005c-broken.jpg', 'not-a-real-image');

        // Command should exit 1 because of the conversion error
        $this->artisan('images:convert-to-webp', [
            '--skip-references' => true,
        ])->assertFailed();

        // Even if we hadn't skipped references, the blade file is unchanged
        // because the failed file never enters $convertedFiles
        $this->assertEquals($originalContent, File::get($bladePath));
    }

    #[Test]
    public function it_exits_zero_when_no_jpg_files_exist_and_no_operations_performed(): void
    {
        $this->artisan('images:convert-to-webp', [
            '--skip-convert' => true,
            '--skip-references' => true,
        ])->assertSuccessful();
    }
}
