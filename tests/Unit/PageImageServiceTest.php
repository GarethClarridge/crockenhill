<?php

namespace Tests\Unit;

use Crockenhill\Services\PageImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image as InterventionImageFacade; // Alias to avoid conflict if any
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver; // Or specific driver used
use Intervention\Image\Image; // The Image instance class
use Tests\TestCase;
use Mockery; // For mocking Image instance methods if needed

class PageImageServiceTest extends TestCase
{
    protected PageImageService $pageImageService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pageImageService = new PageImageService();
    }

    protected function tearDown(): void
    {
        Mockery::close(); // Clean up Mockery expectations
        parent::tearDown();
    }

    /** @test */
    public function handle_image_upload_processes_and_saves_images_correctly()
    {
        // 1. Setup: Mock dependencies
        $fakeFile = UploadedFile::fake()->image('test_image.jpg', 2000, 1500);
        $testSlug = 'test-page-slug';

        Storage::fake('public_images');

        // Mock the Intervention Image facade and its chain
        $mockImageInstance = Mockery::mock(Image::class); // Mock the Image instance
        $mockImageInstance->shouldReceive('resize')->times(2)->andReturnSelf(); // resize called twice
        $mockImageInstance->shouldReceive('encode')->with('jpg')->times(2)->andReturn('fake-image-data'); // encode called twice

        InterventionImageFacade::shouldReceive('make')->once()->with($fakeFile->getRealPath())->andReturn($mockImageInstance);

        // 2. Action: Call the method
        $this->pageImageService->handleImageUpload($fakeFile, $testSlug);

        // 3. Assertions
        // Assert directories were "created" (Storage::fake intercepts this)
        // We can't directly assert makeDirectory on Storage::fake in a unit test style
        // without deeper mocking of the StorageManager.
        // Instead, we rely on `put` succeeding which implies directory existence for Storage::fake.

        // Assert images were "put" into storage with correct paths
        Storage::disk('public_images')->assertExists('images/headings/large/' . $testSlug . '.jpg');
        Storage::disk('public_images')->assertExists('images/headings/small/' . $testSlug . '.jpg');

        // Verify that the content put was what encode returned
        // This is a bit more involved with Storage::fake as it doesn't directly give content for assertContentIs
        // but assertExists checks that files were created by the put operations.
    }

    /** @test */
    public function delete_images_removes_images_from_storage()
    {
        // 1. Setup: Mock dependencies and create dummy files
        $testSlug = 'test-page-to-delete';
        Storage::fake('public_images');

        $largePath = 'images/headings/large/' . $testSlug . '.jpg';
        $smallPath = 'images/headings/small/' . $testSlug . '.jpg';

        // Create dummy files in the fake storage
        Storage::disk('public_images')->put($largePath, 'dummy large image data');
        Storage::disk('public_images')->put($smallPath, 'dummy small image data');

        // Ensure files exist before deletion
        Storage::disk('public_images')->assertExists($largePath);
        Storage::disk('public_images')->assertExists($smallPath);

        // 2. Action: Call the method
        $this->pageImageService->deleteImages($testSlug);

        // 3. Assertions
        // Assert images are now missing from storage
        Storage::disk('public_images')->assertMissing($largePath);
        Storage::disk('public_images')->assertMissing($smallPath);
    }
}
