<?php

namespace Tests\Feature;

use Crockenhill\Page;
use Crockenhill\Meeting;
use Crockenhill\Services\PageImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class PageControllerTest extends TestCase
{
    use RefreshDatabase;

    // No specific setUp user needed if Gate is mocked per test.

    private function createPage(array $attributes = []): Page
    {
        $defaults = [
            'heading' => 'Old Page Heading',
            'slug' => 'old-page-heading',
            'area' => 'General',
            'markdown' => 'Some markdown content.',
            'body' => '<p>Some markdown content.</p>',
            'description' => 'Test page description',
            'navigation' => true,
        ];
        return Page::create(array_merge($defaults, $attributes));
    }

    private function createMeeting(array $attributes = []): Meeting
    {
        $defaults = [
            'slug' => 'old-page-heading',
            // Using a very short type due to previous "data truncated" issues.
            // This might still be problematic if the actual column is extremely short.
            'type' => 'Old',
            'day' => 'Sunday',
            'location' => 'Church',
            'who' => 'Everyone',
            'pictures' => 0,
        ];
        return Meeting::create(array_merge($defaults, $attributes));
    }

    /** @test */
    public function it_updates_page_and_renames_images_if_slug_changes_and_no_new_image()
    {
        $page = $this->createPage(['heading' => 'Old Title', 'slug' => 'old-title']);
        $oldSlug = $page->slug;
        $newHeading = 'New Page Title';
        $newSlug = Str::slug($newHeading);

        $this->mock(PageImageService::class, function (MockInterface $mock) use ($oldSlug, $newSlug) {
            $mock->shouldReceive('renameImages')->once()->with($oldSlug, $newSlug);
            $mock->shouldNotReceive('deleteImages');
            $mock->shouldNotReceive('handleImageUpload');
        });

        Gate::shouldReceive('denies')->with('edit-pages')->andReturn(false);

        $updateData = [
            'heading' => $newHeading,
            'description' => $page->description,
            'area' => $page->area,
            'navigation-radio' => $page->navigation ? 'yes' : 'no',
            'markdown' => $page->markdown,
        ];
        // Using direct URL due to previous 404 issues with route() helper.
        $response = $this->put('/church/members/pages/' . $page->slug, $updateData);


        $response->assertRedirect();
        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'heading' => $newHeading,
            'slug' => $newSlug,
        ]);
    }

    /** @test */
    public function it_updates_page_deletes_old_images_and_uploads_new_if_slug_changes_and_new_image_is_uploaded()
    {
        $page = $this->createPage(['heading' => 'Old Title Slug Change New Image', 'slug' => 'old-title-slug-change-new-image']);
        $oldSlug = $page->slug;
        $newHeading = 'New Title Slug Change New Image';
        $newSlug = Str::slug($newHeading);

        Storage::fake('public_images');
        $newImageFile = UploadedFile::fake()->image('new_heading_image.jpg');

        $this->mock(PageImageService::class, function (MockInterface $mock) use ($oldSlug, $newSlug, $newImageFile) {
            $mock->shouldReceive('deleteImages')->once()->with($oldSlug);
            $mock->shouldReceive('handleImageUpload')->once()->with(
                \Mockery::on(function ($file) use ($newImageFile) {
                    return $file->getClientOriginalName() === $newImageFile->getClientOriginalName();
                }), $newSlug
            );
            $mock->shouldNotReceive('renameImages');
        });

        Gate::shouldReceive('denies')->with('edit-pages')->andReturn(false);

        $updateData = [
            'heading' => $newHeading,
            'description' => $page->description,
            'area' => $page->area,
            'navigation-radio' => $page->navigation ? 'yes' : 'no',
            'markdown' => $page->markdown,
            'heading-image' => $newImageFile,
        ];

        $response = $this->put(route('pages.update', $page->slug), $updateData);

        $response->assertRedirect();
        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'heading' => $newHeading,
            'slug' => $newSlug,
        ]);
    }

    /** @test */
    public function it_updates_page_and_associated_meeting_if_slug_changes()
    {
        $page = $this->createPage(['heading' => 'Page With Meeting', 'slug' => 'page-with-meeting']);
        $meeting = $this->createMeeting(['slug' => 'page-with-meeting', 'type' => 'OldType']); // Ensure type is short

        $oldPageSlug = $page->slug;
        $originalMeetingType = $meeting->type; // Capture original type
        $newPageHeading = 'Updated Page With Meeting';
        $newPageSlug = Str::slug($newPageHeading);

        $this->mock(PageImageService::class, function (MockInterface $mock) use ($oldPageSlug, $newPageSlug) {
            $mock->shouldReceive('renameImages')->with($oldPageSlug, $newPageSlug)->once();
        });

        Gate::shouldReceive('denies')->with('edit-pages')->andReturn(false);

        $updateData = [
            'heading' => $newPageHeading,
            'description' => $page->description,
            'area' => $page->area,
            'navigation-radio' => $page->navigation ? 'yes' : 'no',
            'markdown' => $page->markdown,
        ];

        $response = $this->put(route('pages.update', $page->slug), $updateData);

        $response->assertRedirect();
        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'heading' => $newPageHeading,
            'slug' => $newPageSlug,
        ]);
        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'slug' => $newPageSlug,         // Meeting slug updated
            'type' => $originalMeetingType, // Meeting type REMAINS UNCHANGED
        ]);
    }

    /** @test */
    public function it_updates_page_and_does_not_fail_if_slug_changes_and_no_associated_meeting_exists()
    {
        $page = $this->createPage(['heading' => 'Page No Meeting', 'slug' => 'page-no-meeting']);
        $oldPageSlug = $page->slug;
        $newPageHeading = 'Updated Page No Meeting';
        $newPageSlug = Str::slug($newPageHeading);

        $this->assertDatabaseMissing('meetings', ['slug' => $oldPageSlug]);

        $this->mock(PageImageService::class, function (MockInterface $mock) use ($oldPageSlug, $newPageSlug) {
            $mock->shouldReceive('renameImages')->with($oldPageSlug, $newPageSlug)->once();
        });

        Gate::shouldReceive('denies')->with('edit-pages')->andReturn(false);

        $updateData = [
            'heading' => $newPageHeading,
            'description' => $page->description,
            'area' => $page->area,
            'navigation-radio' => $page->navigation ? 'yes' : 'no',
            'markdown' => $page->markdown,
        ];

        $response = $this->put(route('pages.update', $page->slug), $updateData);

        $response->assertRedirect();
        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'heading' => $newPageHeading,
            'slug' => $newPageSlug,
        ]);
    }
}
