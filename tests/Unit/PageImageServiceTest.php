<?php

namespace Tests\Unit;

use Crockenhill\Services\PageImageService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PageImageServiceTest extends TestCase
{
    protected $pageImageService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pageImageService = new PageImageService();
        Storage::fake('public_images');
    }

    /**
     * @test
     */
    public function it_renames_images_when_old_images_exist()
    {
        $oldSlug = 'old-slug';
        $newSlug = 'new-slug';

        $storageDisk = 'public_images';
        $largePathDir = 'images/headings/large';
        $smallPathDir = 'images/headings/small';

        $oldLargePath = $largePathDir . '/' . $oldSlug . '.jpg';
        $newLargePath = $largePathDir . '/' . $newSlug . '.jpg';
        $oldSmallPath = $smallPathDir . '/' . $oldSlug . '.jpg';
        $newSmallPath = $smallPathDir . '/' . $newSlug . '.jpg';

        Storage::disk($storageDisk)->put($oldLargePath, 'dummy content');
        Storage::disk($storageDisk)->put($oldSmallPath, 'dummy content');

        // Setting up expectations using Mockery's spy or partial mock features if available,
        // or by asserting Storage facade calls if direct mocking is complex.
        // For simplicity in this prompt, we'll rely on Storage::fake and assert file presence/absence.
        // More robust tests would use Storage::shouldReceive with specific expectations.

        $this->pageImageService->renameImages($oldSlug, $newSlug);

        Storage::disk($storageDisk)->assertMissing($oldLargePath);
        Storage::disk($storageDisk)->assertExists($newLargePath);
        Storage::disk($storageDisk)->assertMissing($oldSmallPath);
        Storage::disk($storageDisk)->assertExists($newSmallPath);
    }

    /**
     * @test
     */
    public function it_attempts_to_rename_images_even_if_old_images_do_not_exist()
    {
        $oldSlug = 'non-existent-old-slug';
        $newSlug = 'new-slug-for-non-existent';

        $storageDisk = 'public_images';
        $largePathDir = 'images/headings/large';
        $smallPathDir = 'images/headings/small';

        $oldLargePath = $largePathDir . '/' . $oldSlug . '.jpg';
        $newLargePath = $largePathDir . '/' . $newSlug . '.jpg';
        $oldSmallPath = $smallPathDir . '/' . $oldSlug . '.jpg';
        $newSmallPath = $smallPathDir . '/' . $newSlug . '.jpg';

        // Ensure files don't exist initially
        Storage::disk($storageDisk)->assertMissing($oldLargePath);
        Storage::disk($storageDisk)->assertMissing($oldSmallPath);

        $this->pageImageService->renameImages($oldSlug, $newSlug);

        // Assert old files are still missing and new files were not created from non-existent ones
        Storage::disk($storageDisk)->assertMissing($oldLargePath);
        Storage::disk($storageDisk)->assertMissing($newLargePath); // Should not have been created
        Storage::disk($storageDisk)->assertMissing($oldSmallPath);
        Storage::disk($storageDisk)->assertMissing($newSmallPath); // Should not have been created
    }
}
