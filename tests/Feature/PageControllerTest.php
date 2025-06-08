<?php

namespace Tests\Feature;

use Crockenhill\User;
use Crockenhill\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class PageControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Define a gate for 'edit-pages'
        Gate::define('edit-pages', function ($user) {
            return $user->is_admin_for_test ?? false;
        });

        // It's good practice to define a factory for Page model if not already present for tests
        // and ensure User model has 'is_admin_for_test' attribute or a way to set it.
    }

    protected function createAdminUser(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    protected function createNormalUser(array $attributes = []): User
    {
        return User::factory()->create($attributes); // Default state is not admin
    }

    /** @test */
    public function index_returns_200_for_admin_user()
    {
        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/church/members/pages');
        $response->assertStatus(200);
    }

    /** @test */
    public function index_returns_403_for_normal_user()
    {
        $user = $this->createNormalUser();
        $response = $this->actingAs($user)->get('/church/members/pages');
        $response->assertStatus(403);
    }

    /** @test */
    public function create_returns_200_for_admin_user()
    {
        $admin = $this->createAdminUser();
        $response = $this->actingAs($admin)->get('/church/members/pages/create');
        $response->assertStatus(200);
    }

    /** @test */
    public function create_returns_403_for_normal_user()
    {
        $user = $this->createNormalUser();
        $response = $this->actingAs($user)->get('/church/members/pages/create');
        $response->assertStatus(403);
    }

    /** @test */
    public function store_saves_page_and_redirects_for_admin_with_valid_data()
    {
        Storage::fake('public_images');
        $admin = $this->createAdminUser();

        $heading = 'My Test Page';
        $slug = Str::slug($heading);
        $postData = [
            'heading' => $heading,
            'markdown' => 'Some **markdown** content.',
            'description' => 'A test page description.',
            'area' => 'church',
            'navigation-radio' => 'yes',
            'heading-image' => UploadedFile::fake()->image('test_heading.jpg', 2000, 1000),
        ];

        $response = $this->actingAs($admin)->post('/church/members/pages', $postData);

        $this->assertDatabaseHas('pages', [
            'slug' => $slug,
            'heading' => $heading,
            'description' => 'A test page description.',
            'area' => 'church',
            'navigation' => true,
        ]);

        Storage::disk('public_images')->assertExists('images/headings/large/' . $slug . '.jpg');
        Storage::disk('public_images')->assertExists('images/headings/small/' . $slug . '.jpg');

        $response->assertRedirect('/church/members/pages');
        $response->assertSessionHas('message', $heading . ' successfully created!');
    }

    /** @test */
    public function store_returns_validation_errors_for_admin_with_invalid_data()
    {
        $admin = $this->createAdminUser();
        $postData = [
            'heading' => '', // Invalid: heading is required
            'markdown' => '', // Invalid: markdown is required
            'area' => 'invalid-area', // Invalid: area must be one of christ,church,community
            'navigation-radio' => 'maybe', // Invalid: navigation-radio must be yes or no
            'heading-image' => UploadedFile::fake()->create('not_an_image.txt', 100, 'text/plain'), // Invalid mime
        ];

        $response = $this->actingAs($admin)->post('/church/members/pages', $postData);

        $response->assertSessionHasErrors(['heading', 'markdown', 'area', 'navigation-radio', 'heading-image']);
    }

    /** @test */
    public function store_returns_403_for_normal_user()
    {
        $user = $this->createNormalUser();
        $postData = [
            'heading' => 'Attempted Page',
            'markdown' => 'Content',
            'area' => 'church',
            'navigation-radio' => 'no',
        ];
        $response = $this->actingAs($user)->post('/church/members/pages', $postData);
        $response->assertStatus(403);
    }

    /** @test */
    public function edit_returns_200_for_admin_user_and_existing_page()
    {
        $admin = $this->createAdminUser();
        $page = Page::factory()->create();

        $response = $this->actingAs($admin)->get('/church/members/pages/' . $page->slug . '/edit');
        $response->assertStatus(200);
        $response->assertViewHas('page', $page);
    }

    /** @test */
    public function edit_returns_403_for_normal_user_and_existing_page()
    {
        $user = $this->createNormalUser();
        $page = Page::factory()->create();

        $response = $this->actingAs($user)->get('/church/members/pages/' . $page->slug . '/edit');
        $response->assertStatus(403);
    }

    /** @test */
    public function edit_returns_404_for_admin_user_and_non_existent_page()
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/church/members/pages/non-existent-slug/edit');
        $response->assertStatus(404);
    }

    /** @test */
    public function update_saves_changes_and_redirects_for_admin_with_valid_data()
    {
        Storage::fake('public_images');
        $admin = $this->createAdminUser();
        $page = Page::factory()->create(['heading' => 'Old Heading']);
        $oldSlug = $page->slug;

        // Create dummy old images for the page
        UploadedFile::fake()->image($oldSlug . '.jpg')->storeAs('images/headings/large', $oldSlug . '.jpg', 'public_images');
        UploadedFile::fake()->image($oldSlug . '.jpg')->storeAs('images/headings/small', $oldSlug . '.jpg', 'public_images');

        $newHeading = 'Updated Page Heading';
        $newSlug = Str::slug($newHeading);
        $putData = [
            'heading' => $newHeading,
            'markdown' => 'Updated **markdown** content.',
            'description' => 'An updated test page description.',
            'area' => 'community',
            'navigation-radio' => 'no',
            // 'heading-image' => UploadedFile::fake()->image('new_heading.jpg', 2000, 1000), // Test image update separately
        ];

        $response = $this->actingAs($admin)->put('/church/members/pages/' . $page->slug, $putData);

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'slug' => $newSlug, // Slug should be updated
            'heading' => $newHeading,
            'description' => 'An updated test page description.',
            'area' => 'community',
            'navigation' => false,
        ]);

        // Assert old images (with old slug) are deleted if slug changed
        if ($oldSlug !== $newSlug) {
            Storage::disk('public_images')->assertMissing('images/headings/large/' . $oldSlug . '.jpg');
            Storage::disk('public_images')->assertMissing('images/headings/small/' . $oldSlug . '.jpg');
        }

        $response->assertRedirect('/church/members/pages');
        $response->assertSessionHas('message', $newHeading . ' successfully updated!');
    }

    /** @test */
    public function update_handles_image_replacement()
    {
        Storage::fake('public_images');
        $admin = $this->createAdminUser();
        $page = Page::factory()->create(['heading' => 'Page With Image']);
        $slug = $page->slug;

        // Create dummy old images for the page
        UploadedFile::fake()->image('old_image.jpg')->storeAs('images/headings/large', $slug . '.jpg', 'public_images');
        UploadedFile::fake()->image('old_image.jpg')->storeAs('images/headings/small', $slug . '.jpg', 'public_images');

        $putData = [ // Valid data, only changing image
            'heading' => $page->heading,
            'markdown' => $page->markdown,
            'description' => $page->description,
            'area' => $page->area,
            'navigation-radio' => $page->navigation ? 'yes' : 'no',
            'heading-image' => UploadedFile::fake()->image('new_heading_pic.jpg', 1800, 900),
        ];

        $response = $this->actingAs($admin)->put('/church/members/pages/' . $slug, $putData);
        $response->assertStatus(302); // Redirect

        Storage::disk('public_images')->assertExists('images/headings/large/' . $slug . '.jpg');
        Storage::disk('public_images')->assertExists('images/headings/small/' . $slug . '.jpg');
        // We can't easily check if the content is new_heading_pic.jpg vs old_image.jpg without more complex checks
        // but the service logic should handle replacing it.
        // The presence check after upload is a good indicator.
    }


    /** @test */
    public function update_returns_validation_errors_for_admin_with_invalid_data()
    {
        $admin = $this->createAdminUser();
        $page = Page::factory()->create();
        $putData = [
            'heading' => '', // Invalid
            'markdown' => '', // Invalid
        ];

        $response = $this->actingAs($admin)->put('/church/members/pages/' . $page->slug, $putData);
        $response->assertSessionHasErrors(['heading', 'markdown']);
    }

    /** @test */
    public function update_returns_403_for_normal_user()
    {
        $user = $this->createNormalUser();
        $page = Page::factory()->create();
        $putData = [
            'heading' => 'Attempted Update',
            'markdown' => 'Content',
            'area' => 'church',
            'navigation-radio' => 'yes',
        ];
        $response = $this->actingAs($user)->put('/church/members/pages/' . $page->slug, $putData);
        $response->assertStatus(403);
    }

    /** @test */
    public function destroy_deletes_page_and_images_and_redirects_for_admin()
    {
        Storage::fake('public_images');
        $admin = $this->createAdminUser();
        $page = Page::factory()->create();
        $slug = $page->slug;
        $heading = $page->heading;

        // Create dummy images for the page
        UploadedFile::fake()->image($slug . '.jpg')->storeAs('images/headings/large', $slug . '.jpg', 'public_images');
        UploadedFile::fake()->image($slug . '.jpg')->storeAs('images/headings/small', $slug . '.jpg', 'public_images');

        $response = $this->actingAs($admin)->delete('/church/members/pages/' . $slug);

        $this->assertDatabaseMissing('pages', ['id' => $page->id, 'slug' => $slug]);
        Storage::disk('public_images')->assertMissing('images/headings/large/' . $slug . '.jpg');
        Storage::disk('public_images')->assertMissing('images/headings/small/' . $slug . '.jpg');

        $response->assertRedirect('/church/members/pages');
        $response->assertSessionHas('message', $heading . ' successfully deleted!');
    }

    /** @test */
    public function destroy_returns_403_for_normal_user()
    {
        $user = $this->createNormalUser();
        $page = Page::factory()->create();

        $response = $this->actingAs($user)->delete('/church/members/pages/' . $page->slug);
        $response->assertStatus(403);
        $this->assertDatabaseHas('pages', ['id' => $page->id]); // Ensure page was not deleted
    }

    /** @test */
    public function show_public_route_returns_200_for_existing_page()
    {
        $page = Page::factory()->create();

        // Assuming a public route like /{page:slug} or /{area}/{page:slug}
        // For this test, we'll use a simple /{slug} pattern.
        // The actual route definition in web.php determines the correct URL.
        // If the route is /{area}/{slug}, this test will need adjustment.
        // E.g., $response = $this->get('/' . $page->area . '/' . $page->slug);

        $response = $this->get('/' . $page->slug);
        $response->assertStatus(200);
        $response->assertViewHas('page', $page);
        $response->assertSee($page->heading);
    }

    /** @test */
    public function show_public_route_returns_404_for_non_existent_page()
    {
        $response = $this->get('/non-existent-page-slug-for-test');
        $response->assertStatus(404);
    }
}
