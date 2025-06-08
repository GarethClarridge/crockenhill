<?php

// Remove unused default imports if not needed, but keep for now.
// use Illuminate\Foundation\Testing\WithoutMiddleware;
// use Illuminate\Foundation\Testing\DatabaseMigrations;
// use Illuminate\Foundation\Testing\DatabaseTransactions;

// Added imports
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use App\Models\User; // Assuming this is the User model path
use Crockenhill\Sermon; // Assuming Sermon model is Crockenhill\Sermon
use Illuminate\Foundation\Testing\RefreshDatabase; // Useful for a clean DB state
use Illuminate\Support\Str; // Added for Str::slug

// Ensure the class extends Tests\TestCase if that's the project structure for Laravel 8+
// Default was 'class SermonTest extends TestCase' which usually implies TestCase from PHPUnit or an alias.
// For Laravel, it should extend \Tests\TestCase. Assuming the original TestCase alias is fine.
class SermonTest extends TestCase
{
    use RefreshDatabase; // Add this line
    /**
     * A basic test example.
     *
     * @return void
     */



    public function testCreateandDestroy() // Or rename to something like test_sermon_creation_and_storage
    {
        Auth::loginUsingId(2); // Keeping this for now as per original test for simplicity

        Storage::fake('public');

        $file = UploadedFile::fake()->create('test_sermon.mp3', 1024, 'audio/mpeg');

        $sermonData = [
            'title' => 'Test Sermon Title',
            'preacher' => 'Test Preacher',
            'series' => 'Test Series',
            'reference' => 'John 3:16',
            'date' => now()->format('Y-m-d'),
            'service' => 'morning',
            'file' => $file,
            'point-1' => 'Main Point 1 Value',
            'sub-point-1-1' => 'Sub Point 1.1 Value',
        ];

        // Attempt to use a named route; if not available, this will fail and indicate
        // the need to use a hardcoded path after inspecting routes/web.php.
        // Common Laravel practice is to name resource routes e.g., 'sermons.store'.
        $response = $this->post(route('sermons.store'), $sermonData);

        // Check for successful redirect and session message
        $response->assertStatus(302);
        $response->assertSessionHas('message', '"Test Sermon Title" successfully uploaded!');

        // Assert redirection to the sermon index page (assuming route name 'sermonIndex')
        // This name was used in SermonController redirects.
        $response->assertRedirect(route('sermonIndex'));

        // Assert file was stored
        // Retrieve the sermon from the database to get the stored filename
        $sermon = Sermon::where('title', 'Test Sermon Title')->orderBy('id', 'desc')->first();
        $this->assertNotNull($sermon, 'Sermon was not created in the database.');
        $this->assertInstanceOf(Sermon::class, $sermon); // Ensure it's the correct model

        Storage::disk('public')->assertExists($sermon->filename);

        // Assert points were stored correctly (as an array, due to model cast)
        $this->assertIsArray($sermon->points);
        $this->assertCount(1, $sermon->points);
        $this->assertArrayHasKey('point', $sermon->points[0]);
        $this->assertEquals('Main Point 1 Value', $sermon->points[0]['point']);
        $this->assertArrayHasKey('sub_points', $sermon->points[0]);
        $this->assertIsArray($sermon->points[0]['sub_points']);
        $this->assertCount(1, $sermon->points[0]['sub_points']);
        $this->assertEquals('Sub Point 1.1 Value', $sermon->points[0]['sub_points'][0]);

        // The remainder of the original test (editing, deleting) is removed by this replacement.
        // Further tests should be created for those actions separately.
        Auth::logout();
    }
    public function testAll()
    {
      $this->visit('/sermons')
        ->click('Find older sermons')
        ->seePageIs('/sermons/all');
    }

    public function testUpdate() // This will be effectively replaced by more specific tests below
    {

    }

    // Helper method to create a sermon for tests
    protected function createTestSermon(array $overrides = []): Sermon
    {
        // Helper to create a sermon, assuming a factory exists or create directly.
        // If no factory, create directly. For simplicity here, direct creation.
        // Ensure required fields are present.
        return Sermon::create(array_merge([
            'title' => 'Original Sermon Title',
            'slug' => 'original-sermon-title', // Slugs are generated in controller, but for test setup, can be explicit
            'preacher' => 'Original Preacher',
            'series' => 'Original Series',
            'reference' => 'Gen 1:1',
            'date' => now()->subDay()->format('Y-m-d'), // Ensure it's a valid date
            'service' => 'morning',
            'filename' => 'sermons/original.mp3', // Needs a dummy filename
            'points' => json_encode([['point' => 'Old Point', 'sub_points' => []]]), // Stored as JSON string by factory/seeder
        ], $overrides));
    }

    public function test_update_sermon_successful()
    {
        Auth::loginUsingId(2); // Authorized user
        $sermon = $this->createTestSermon();

        $updatedData = [
            'title' => 'Updated Sermon Title',
            'preacher' => 'Updated Preacher',
            'series' => 'Updated Series',
            'reference' => 'Exodus 20:1',
            'date' => now()->format('Y-m-d'),
            'service' => 'evening',
            'points' => json_encode([['point' => 'New Point 1', 'sub_points' => ['New Sub 1.1']]]),
        ];

        $sermonDate = new \DateTime($sermon->date); // Ensure date is treated as such
        $response = $this->put(
            "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}",
            $updatedData
        );

        $response->assertStatus(302);
        $response->assertRedirect(route('sermonIndex')); // As per controller
        $response->assertSessionHas('message', '"Updated Sermon Title" successfully updated!');

        $sermon->refresh();
        $this->assertEquals('Updated Sermon Title', $sermon->title);
        $this->assertEquals('Updated Preacher', $sermon->preacher);
        // Points are stored as array by model, so compare array
        $this->assertEquals([['point' => 'New Point 1', 'sub_points' => ['New Sub 1.1']]], $sermon->points);
        // Also check new slug
        $this->assertEquals(Str::slug('Updated Sermon Title'), $sermon->slug);

        Auth::logout();
    }

    public function test_update_sermon_validation_failures()
    {
        Auth::loginUsingId(2);
        $sermon = $this->createTestSermon();
        $sermonDate = new \DateTime($sermon->date);
        $updateUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";

        // Test: Missing title
        $response = $this->put($updateUrl, ['title' => '', 'date' => $sermon->date, 'service' => $sermon->service, 'preacher' => $sermon->preacher]); // Added other required fields
        $response->assertStatus(302);
        $response->assertSessionHasErrors('title');

        // Test: Invalid JSON for points
        $response = $this->put($updateUrl, ['title' => 'Valid Title Update', 'date' => $sermon->date, 'service' => $sermon->service, 'preacher' => $sermon->preacher, 'points' => 'this is not json']); // Added other required fields
        $response->assertStatus(302);
        $response->assertSessionHasErrors('points');

        Auth::logout();
    }

    public function test_update_sermon_unauthorized_user()
    {
        $sermon = $this->createTestSermon();
        Auth::logout(); // Ensure guest or non-admin
        $sermonDate = new \DateTime($sermon->date);

        $response = $this->put(
            "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}",
            ['title' => 'Attempted Update', 'date' => $sermon->date, 'service' => $sermon->service, 'preacher' => $sermon->preacher] // Added other required fields
        );
        $response->assertStatus(403);
    }

    public function test_update_sermon_not_found()
    {
        Auth::loginUsingId(2);
        $response = $this->put(
            "/christ/sermons/1900/01/non-existent-slug",
            ['title' => 'Update Non Existent', 'date' => '1900-01-01', 'service' => 'morning', 'preacher' => 'Test'] // Added other required fields for UpdateSermonRequest
        );
        $response->assertStatus(404);
        Auth::logout();
    }

    public function test_store_sermon_fails_for_unauthorized_user()
    {
        // Assuming no user is logged in (guest) or a user without permission is logged in.
        // If there's a known non-admin user ID, use Auth::loginUsingId(non_admin_id);
        // For now, let's test as a guest (no authentication).
        Auth::logout(); // Ensure no one is logged in from previous tests if state persists

        Storage::fake('public');
        $file = UploadedFile::fake()->create('test_sermon.mp3', 100, 'audio/mpeg');

        $sermonData = [
            'title' => 'Attempted Sermon Title',
            'preacher' => 'Test Preacher',
            'date' => now()->format('Y-m-d'),
            'service' => 'morning',
            'file' => $file,
        ];

        // Assuming 'sermons.store' is the named route for POST request to store a sermon
        $response = $this->post(route('sermons.store'), $sermonData);

        // Expect a 403 Forbidden response as StoreSermonRequest::authorize() should fail
        $response->assertStatus(403);
        Storage::disk('public')->assertMissing('sermons/' . $file->hashName()); // Ensure file not stored
    }

    public function test_store_sermon_validation_failures()
    {
        // Log in as an authorized user (e.g., User ID 2, assuming it has 'edit-sermons' permission)
        // In a real scenario, ideally use a factory: $admin = User::factory()->admin()->create(); $this->actingAs($admin);
        Auth::loginUsingId(2); // Continue using this as per existing test structure

        Storage::fake('public');

        // Test case 1: Missing title
        $response_missing_title = $this->post(route('sermons.store'), [
            'file' => UploadedFile::fake()->create('sermon.mp3', 100),
            'date' => '2024-01-01',
            'service' => 'morning',
            'preacher' => 'Test Preacher',
        ]);
        $response_missing_title->assertStatus(302); // Redirect back on validation failure
        $response_missing_title->assertSessionHasErrors('title');

        // Test case 2: File too large
        $largeFile = UploadedFile::fake()->create('large_sermon.mp3', 60000, 'audio/mpeg'); // 60MB
        $response_file_too_large = $this->post(route('sermons.store'), [
            'title' => 'Sermon with Large File',
            'file' => $largeFile,
            'date' => '2024-01-01',
            'service' => 'morning',
            'preacher' => 'Test Preacher',
        ]);
        $response_file_too_large->assertStatus(302);
        $response_file_too_large->assertSessionHasErrors('file');
        Storage::disk('public')->assertMissing('sermons/' . $largeFile->hashName());


        // Test case 3: Invalid date format
        $response_invalid_date = $this->post(route('sermons.store'), [
            'title' => 'Sermon with Invalid Date',
            'file' => UploadedFile::fake()->create('sermon.mp3', 100),
            'date' => '01/01/2024', // Invalid format
            'service' => 'morning',
            'preacher' => 'Test Preacher',
        ]);
        $response_invalid_date->assertStatus(302);
        $response_invalid_date->assertSessionHasErrors('date');

        // Test case 4: Missing required 'file'
        $response_missing_file = $this->post(route('sermons.store'), [
            'title' => 'Sermon Missing File',
            'date' => '2024-01-01',
            'service' => 'morning',
            'preacher' => 'Test Preacher',
        ]);
        $response_missing_file->assertStatus(302);
        $response_missing_file->assertSessionHasErrors('file');

        Auth::logout();
    }

    public function test_destroy_sermon_successful()
    {
        Auth::loginUsingId(2); // Authorized user
        $sermon = $this->createTestSermon();
        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]); // Confirm it exists first

        $sermonDate = new \DateTime($sermon->date);
        $deleteUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";

        $response = $this->delete($deleteUrl);

        $response->assertStatus(302);
        $response->assertRedirect(route('sermonIndex')); // As per controller
        $response->assertSessionHas('message', 'Sermon successfully deleted!');

        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);
        // Note: This test does not assert file deletion from storage, as the controller method doesn't do it.
        Auth::logout();
    }

    public function test_destroy_sermon_unauthorized_user()
    {
        $sermon = $this->createTestSermon();
        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]);
        Auth::logout(); // Ensure guest or non-admin

        $sermonDate = new \DateTime($sermon->date);
        $deleteUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";

        $response = $this->delete($deleteUrl);

        $response->assertStatus(403);
        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]); // Ensure it was not deleted
    }

    public function test_destroy_sermon_not_found()
    {
        Auth::loginUsingId(2); // Authorized user

        $deleteUrl = "/christ/sermons/1900/01/non-existent-slug";
        $response = $this->delete($deleteUrl);

        $response->assertStatus(404);
        Auth::logout();
    }

    public function test_show_sermon_page_loads_for_existing_sermon()
    {
        $sermon = $this->createTestSermon(['title' => 'Specific Sermon Title for Show Test']);
        $sermonDate = new \DateTime($sermon->date);
        $showUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";

        $response = $this->get($showUrl);

        $response->assertStatus(200);
        $response->assertSee('Specific Sermon Title for Show Test');
        // Check for points display structure if $sermon has points
        if (!empty($sermon->points) && is_array($sermon->points)) {
            $firstPoint = $sermon->points[0];
            if(is_array($firstPoint) && isset($firstPoint['point'])) {
                 $response->assertSee($firstPoint['point']);
            } elseif (is_scalar($firstPoint)) {
                 $response->assertSee((string)$firstPoint);
            }
        }
    }

    public function test_show_sermon_returns_404_for_non_existent_sermon()
    {
        $response = $this->get("/christ/sermons/1900/01/non-existent-slug");
        $response->assertStatus(404);
    }

    public function test_sermon_index_page_loads()
    {
        // The refactored create test already asserts redirect to sermonIndex, implying it exists.
        // This test just ensures it loads directly.
        // Assuming route('sermonIndex') points to '/christ/sermons' or '/sermons'
        $sermon = $this->createTestSermon(['title' => 'Latest Sermon on Index']);

        // The index page in SermonController uses route('sermonIndex') for redirects.
        // Let's assume this is the correct route name for the index page.
        $response = $this->get(route('sermonIndex'));
        $response->assertStatus(200);
        $response->assertSee('Latest Sermon on Index'); // Check if latest sermon appears
    }

    public function test_sermon_all_page_loads()
    {
        // The existing testAll() method uses visit('/sermons')->click('Find older sermons')->seePageIs('/sermons/all');
        // This can be simplified or kept. A direct GET is simpler for a basic load test.
        $sermon = $this->createTestSermon(['title' => 'Sermon for All Page']);
        $response = $this->get('/sermons/all'); // Path from existing test
        $response->assertStatus(200);
        $response->assertSee('Sermon for All Page');
    }

    public function test_sermon_preachers_page_loads_and_shows_preacher()
    {
        $this->createTestSermon(['preacher' => 'Test Preacher Name']);
        $response = $this->get('/christ/sermons/preachers'); // Path based on controller/view links
        $response->assertStatus(200);
        $response->assertSee('Test Preacher Name');
    }

    public function test_sermon_serieses_page_loads_and_shows_series()
    {
        $this->createTestSermon(['series' => 'Test Series Name']);
        $response = $this->get('/christ/sermons/series'); // Path based on controller/view links
        $response->assertStatus(200);
        $response->assertSee('Test Series Name');
    }

    public function test_single_preacher_page_loads_and_shows_sermon()
    {
        $preacherName = 'John Doe';
        $preacherSlug = Str::slug($preacherName);
        $sermon = $this->createTestSermon(['preacher' => $preacherName, 'title' => 'Sermon by John Doe']);

        $response = $this->get("/christ/sermons/preachers/{$preacherSlug}");
        $response->assertStatus(200);
        $response->assertSee('Sermon by John Doe');
    }

    public function test_single_series_page_loads_and_shows_sermon()
    {
        $seriesName = 'Studies in Genesis';
        $seriesSlug = Str::slug($seriesName);
        $sermon = $this->createTestSermon(['series' => $seriesName, 'title' => 'Genesis Sermon 1']);

        $response = $this->get("/christ/sermons/series/{$seriesSlug}");
        $response->assertStatus(200);
        $response->assertSee('Genesis Sermon 1');
    }

    public function test_single_service_page_loads_and_shows_sermon()
    {
        $serviceType = 'morning'; // Must be 'morning' or 'evening' based on controller
        $sermon = $this->createTestSermon(['service' => $serviceType, 'title' => 'Morning Service Sermon']);

        $response = $this->get("/christ/sermons/service/{$serviceType}");
        $response->assertStatus(200);
        $response->assertSee('Morning Service Sermon');
    }
}
