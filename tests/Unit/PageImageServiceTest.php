<?php

namespace Tests\Unit;

use Crockenhill\Services\PageImageService;
use Illuminate\Http\UploadedFile; // Added this
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

    // Append these methods to the existing PageImageServiceTest class

    /** @test */
    public function it_handles_image_upload_correctly()
    {
        $slug = 'test-slug-for-upload';
        $file = \Illuminate\Http\UploadedFile::fake()->image('test_image.jpg');

        $imageMock = \Mockery::mock('alias:Intervention\Image\Facades\Image');
        $imageInstanceMock = \Mockery::mock('Intervention\Image\Image');

        $imageMock->shouldReceive('make')->with($file->getRealPath())->once()->andReturn($imageInstanceMock);

        $imageInstanceMock->shouldReceive('resize')->with(2000, null, \Mockery::on(function($closure) {
            $constraint = \Mockery::mock();
            $constraint->shouldReceive('aspectRatio')->once();
            $closure($constraint);
            return true;
        }))->once()->andReturnSelf();
        $imageInstanceMock->shouldReceive('encode')->with('jpg')->once()->andReturn('large_image_data');

        $imageInstanceMock->shouldReceive('resize')->with(300, null, \Mockery::on(function($closure) {
            $constraint = \Mockery::mock();
            $constraint->shouldReceive('aspectRatio')->once();
            $closure($constraint);
            return true;
        }))->once()->andReturnSelf();
        $imageInstanceMock->shouldReceive('encode')->with('jpg')->once()->andReturn('small_image_data');

        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->with('public_images')->times(4)->andReturnSelf();
        \Illuminate\Support\Facades\Storage::shouldReceive('makeDirectory')->with('images/headings/large')->once();
        \Illuminate\Support\Facades\Storage::shouldReceive('makeDirectory')->with('images/headings/small')->once();
        \Illuminate\Support\Facades\Storage::shouldReceive('put')->with('images/headings/large/' . $slug . '.jpg', 'large_image_data')->once();
        \Illuminate\Support\Facades\Storage::shouldReceive('put')->with('images/headings/small/' . $slug . '.jpg', 'small_image_data')->once();

        $this->pageImageService->handleImageUpload($file, $slug);
    }

    /** @test */
    public function it_deletes_images_if_they_exist()
    {
        $slug = 'slug-for-existing-images';
        $largePath = 'images/headings/large/' . $slug . '.jpg';
        $smallPath = 'images/headings/small/' . $slug . '.jpg';

        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->with('public_images')->times(4)->andReturnSelf();
        \Illuminate\Support\Facades\Storage::shouldReceive('exists')->with($largePath)->once()->andReturn(true);
        \Illuminate\Support\Facades\Storage::shouldReceive('delete')->with($largePath)->once();
        \Illuminate\Support\Facades\Storage::shouldReceive('exists')->with($smallPath)->once()->andReturn(true);
        \Illuminate\Support\Facades\Storage::shouldReceive('delete')->with($smallPath)->once();

        $this->pageImageService->deleteImages($slug);
    }

    /** @test */
    public function it_does_not_attempt_to_delete_images_if_they_do_not_exist()
    {
        $slug = 'slug-for-nonexistent-images';
        $largePath = 'images/headings/large/' . $slug . '.jpg';
        $smallPath = 'images/headings/small/' . $slug . '.jpg';

        \Illuminate\Support\Facades\Storage::shouldReceive('disk')->with('public_images')->times(2)->andReturnSelf();
        \Illuminate\Support\Facades\Storage::shouldReceive('exists')->with($largePath)->once()->andReturn(false);
        \Illuminate\Support\Facades\Storage::shouldReceive('exists')->with($smallPath)->once()->andReturn(false);
        \Illuminate\Support\Facades\Storage::shouldNotReceive('delete');

        $this->pageImageService->deleteImages($slug);
    }
}
