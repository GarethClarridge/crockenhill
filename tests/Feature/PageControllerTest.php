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
            'type' => 'OldType',
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

        $response = $this->put('/church/members/pages/' . $page->slug, $updateData); // Kept direct URL from previous step

        $response->assertRedirect(route('pages.show', $newSlug));
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

        $response->assertRedirect(route('pages.show', $newSlug));
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
        $meeting = $this->createMeeting(['slug' => 'page-with-meeting', 'type' => 'OldType']);

        $oldPageSlug = $page->slug;
        $originalMeetingType = $meeting->type;
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

        $response->assertRedirect(route('pages.show', $newPageSlug));
        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'heading' => $newPageHeading,
            'slug' => $newPageSlug,
        ]);
        $this->assertDatabaseHas('meetings', [
            'id' => $meeting->id,
            'slug' => $newPageSlug,
            'type' => $originalMeetingType,
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

        $response->assertRedirect(route('pages.show', $newPageSlug));
        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'heading' => $newPageHeading,
            'slug' => $newPageSlug,
        ]);
    }

    /** @test */
    public function test_redirect_logic_when_slug_changes()
    {
        // Common setup for all scenarios
        Gate::shouldReceive('denies')->with('edit-pages')->andReturn(false);

        // --- Scenario A: backUrl is in session and contains the old slug ---
        $pageA = $this->createPage(['heading' => 'Original Heading A', 'slug' => 'original-slug-a']);
        $oldSlugA = $pageA->slug;
        $newHeadingA = 'New Heading A';
        $newSlugA = Str::slug($newHeadingA);

        $this->mock(PageImageService::class, function (MockInterface $mock) use ($oldSlugA, $newSlugA) {
            $mock->shouldReceive('renameImages')->once()->with($oldSlugA, $newSlugA);
        });

        $updateDataA = [
            'heading' => $newHeadingA, 'description' => $pageA->description, 'area' => $pageA->area,
            'navigation-radio' => $pageA->navigation ? 'yes' : 'no', 'markdown' => $pageA->markdown,
        ];

        $backUrlPathA = 'path/' . $oldSlugA . '/context';
        session()->put('backUrl', url($backUrlPathA));

        $responseA = $this->put(route('pages.update', $oldSlugA), $updateDataA);
        $responseA->assertRedirect(url('path/' . $newSlugA . '/context'));
        $this->assertDatabaseHas('pages', ['id' => $pageA->id, 'slug' => $newSlugA]);


        // --- Scenario B: backUrl is in session but does NOT contain the old slug ---
        $pageB = $this->createPage(['heading' => 'Original Heading B', 'slug' => 'original-slug-b']);
        $oldSlugB = $pageB->slug;
        $newHeadingB = 'New Heading B';
        $newSlugB = Str::slug($newHeadingB);

        // Re-mock for this specific scenario if necessary, or ensure the mock is flexible.
        // For this specific service, the mock might be called multiple times if not specific enough.
        // We can create a fresh mock instance.
        $this->mock(PageImageService::class, function (MockInterface $mock) use ($oldSlugB, $newSlugB) {
            $mock->shouldReceive('renameImages')->once()->with($oldSlugB, $newSlugB);
        });

        $updateDataB = [
            'heading' => $newHeadingB, 'description' => $pageB->description, 'area' => $pageB->area,
            'navigation-radio' => $pageB->navigation ? 'yes' : 'no', 'markdown' => $pageB->markdown,
        ];

        $unrelatedBackUrl = url('unrelated/path');
        session()->put('backUrl', $unrelatedBackUrl);

        $responseB = $this->put(route('pages.update', $oldSlugB), $updateDataB);
        $responseB->assertRedirect($unrelatedBackUrl);
        $this->assertDatabaseHas('pages', ['id' => $pageB->id, 'slug' => $newSlugB]);


        // --- Scenario C: No backUrl in session ---
        $pageC = $this->createPage(['heading' => 'Original Heading C', 'slug' => 'original-slug-c']);
        $oldSlugC = $pageC->slug;
        $newHeadingC = 'New Heading C';
        $newSlugC = Str::slug($newHeadingC);

        $this->mock(PageImageService::class, function (MockInterface $mock) use ($oldSlugC, $newSlugC) {
            $mock->shouldReceive('renameImages')->once()->with($oldSlugC, $newSlugC);
        });

        $updateDataC = [
            'heading' => $newHeadingC, 'description' => $pageC->description, 'area' => $pageC->area,
            'navigation-radio' => $pageC->navigation ? 'yes' : 'no', 'markdown' => $pageC->markdown,
        ];

        session()->forget('backUrl');

        $responseC = $this->put(route('pages.update', $oldSlugC), $updateDataC);
        $responseC->assertRedirect(route('pages.show', $newSlugC));
        $this->assertDatabaseHas('pages', ['id' => $pageC->id, 'slug' => $newSlugC]);
    }

    /** @test */
    public function authorized_user_can_delete_a_page()
    {
        Gate::shouldReceive('denies')->with('edit-pages')->andReturn(false)->atLeast()->once();

        $page = $this->createPage(['slug' => 'page-to-delete']);

        $this->mock(PageImageService::class, function (MockInterface $mock) use ($page) {
            $mock->shouldReceive('deleteImages')->once()->with($page->slug);
        });

        $response = $this->delete(route('pages.destroy', $page->slug));

        $response->assertRedirect('/church/members/pages');
        $response->assertSessionHas('message', $page->heading . ' successfully deleted!');
        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }

    /** @test */
    public function unauthorized_user_cannot_delete_a_page()
    {
        Gate::shouldReceive('denies')->with('edit-pages')->andReturn(true)->atLeast()->once();

        $page = $this->createPage(['slug' => 'page-protected-from-delete']);

        $this->mock(PageImageService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('deleteImages');
        });

        $response = $this->delete(route('pages.destroy', $page->slug));

        $response->assertStatus(403);
        $this->assertDatabaseHas('pages', ['id' => $page->id]);
    }

    /** @test */
    public function unauthenticated_user_cannot_delete_a_page()
    {
        $page = $this->createPage(['slug' => 'another-page-to-delete']);

        $response = $this->delete(route('pages.destroy', $page->slug));

        // Assuming redirect to login for web routes.
        // Adjust if API (401/403 JSON) or different auth middleware setup.
        $response->assertRedirectContains(route('login'));
        $this->assertDatabaseHas('pages', ['id' => $page->id]);
    }

    /** @test */
    public function unauthenticated_user_is_redirected_from_pages_index()
    {
        $response = $this->get(route('pages.index'));
        $response->assertRedirectContains(route('login')); // Assumes 'login' is your login route name
    }

    /** @test */
    public function authenticated_user_without_edit_permission_is_forbidden_from_pages_index()
    {
        // If user factory and actingAs is simple, add:
        // $user = \Crockenhill\User::factory()->create(); $this->actingAs($user);
        Gate::shouldReceive('denies')->with('edit-pages')->andReturn(true);

        $response = $this->get(route('pages.index'));
        $response->assertStatus(403);
    }

    /** @test */
    public function authenticated_user_with_edit_permission_can_access_pages_index()
    {
        // If user factory and actingAs is simple, add:
        // $user = \Crockenhill\User::factory()->create(); $this->actingAs($user);
        Gate::shouldReceive('denies')->with('edit-pages')->andReturn(false);

        $response = $this->get(route('pages.index'));
        $response->assertStatus(200);
        $response->assertViewIs('pages.index');
    }
}
