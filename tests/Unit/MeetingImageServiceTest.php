<?php

namespace Tests\Unit;

use Crockenhill\Services\MeetingImageService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log; // For Log::shouldReceive if testing active logging
use Tests\TestCase;

class MeetingImageServiceTest extends TestCase
{
    protected MeetingImageService $meetingImageService;
    protected string $diskName = 'public'; // Matches service
    protected string $basePath = 'images/meetings'; // Matches service

    protected function setUp(): void
    {
        parent::setUp();
        $this->meetingImageService = new MeetingImageService();
    }

    /** @test */
    public function it_renames_directory_if_old_directory_exists()
    {
        $oldSlug = 'old-meeting-slug';
        $newSlug = 'new-meeting-slug';
        $oldDirectoryPath = $this->basePath . '/' . $oldSlug;
        $newDirectoryPath = $this->basePath . '/' . $newSlug;

        Storage::shouldReceive('disk')->with($this->diskName)->twice()->andReturnSelf();
        Storage::shouldReceive('exists')->with($oldDirectoryPath)->once()->andReturn(true);
        Storage::shouldReceive('move')->with($oldDirectoryPath, $newDirectoryPath)->once()->andReturn(true);

        $result = $this->meetingImageService->renameImageDirectory($oldSlug, $newSlug);
        $this->assertTrue($result);
    }

    /** @test */
    public function it_does_not_rename_if_old_directory_does_not_exist()
    {
        $oldSlug = 'non-existent-slug';
        $newSlug = 'another-new-slug';
        $oldDirectoryPath = $this->basePath . '/' . $oldSlug;

        Storage::shouldReceive('disk')->with($this->diskName)->once()->andReturnSelf();
        Storage::shouldReceive('exists')->with($oldDirectoryPath)->once()->andReturn(false);
        Storage::shouldNotReceive('move');

        $result = $this->meetingImageService->renameImageDirectory($oldSlug, $newSlug);
        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_false_and_does_not_operate_for_empty_slugs()
    {
        // If slugs are empty, no Storage methods should be called as the service method should return early.
        // Explicitly not setting any Storage expectations.
        $this->assertFalse($this->meetingImageService->renameImageDirectory('', 'new-slug'), "Empty oldSlug should return false.");
        $this->assertFalse($this->meetingImageService->renameImageDirectory('old-slug', ''), "Empty newSlug should return false.");
    }

    /** @test */
    public function it_returns_false_and_does_not_operate_if_slugs_are_the_same()
    {
        // If slugs are the same, no Storage methods should be called as the service method should return early.
        // Explicitly not setting any Storage expectations.
        $this->assertFalse($this->meetingImageService->renameImageDirectory('same-slug', 'same-slug'), "Identical slugs should return false.");
    }

    /** @test */
    public function it_handles_exception_during_move_and_returns_false()
    {
        $oldSlug = 'slug-causes-error';
        $newSlug = 'new-slug-error-path';
        $oldDirectoryPath = $this->basePath . '/' . $oldSlug;
        $newDirectoryPath = $this->basePath . '/' . $newSlug;

        Storage::shouldReceive('disk')->with($this->diskName)->twice()->andReturnSelf();
        Storage::shouldReceive('exists')->with($oldDirectoryPath)->once()->andReturn(true);
        Storage::shouldReceive('move')->with($oldDirectoryPath, $newDirectoryPath)->once()->andThrow(new \Exception('Disk move failed'));

        // Log::shouldReceive('error')->once(); // Uncomment and refine if active logging is tested

        $result = $this->meetingImageService->renameImageDirectory($oldSlug, $newSlug);
        $this->assertFalse($result, "Should return false if Storage::move throws an exception.");
    }
}
