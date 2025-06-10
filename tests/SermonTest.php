<?php

namespace Tests;

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
  {}

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
    // Corrected URL and HTTP method
    $updateUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}/edit";
    $response = $this->post($updateUrl, $updatedData); // Changed from put() to post() and updated URL

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

    // Now, fetch the sermon's show page and check if points are rendered
    // $sermonDate was defined earlier for the update URL. We need year/month for the show URL.
    // The slug might have changed if the title changed, so use $sermon->slug (refreshed).
    $showUrl = "/christ/sermons/" . (new \DateTime($sermon->date))->format('Y') . "/" . (new \DateTime($sermon->date))->format('m') . "/{$sermon->slug}";
    $showResponse = $this->get($showUrl);
    $showResponse->assertStatus(200);
    $showResponse->assertSee('New Point 1'); // Check for the main point text
    $showResponse->assertSee('New Sub 1.1'); // Check for the sub-point text

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
      if (is_array($firstPoint) && isset($firstPoint['point'])) {
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

  public function test_update_sermon_with_null_points_does_not_render_points_section()
  {
    Auth::loginUsingId(2); // Authorized user
    $sermon = $this->createTestSermon([
      'points' => json_encode([['point' => 'Initial Point', 'sub_points' => ['Initial Sub']]])
    ]);

    $updatedData = [
      'title' => $sermon->title, // Keep other fields same or update as needed
      'date' => $sermon->date,
      'service' => $sermon->service,
      'preacher' => $sermon->preacher,
      'points' => null, // Send null for points (UpdateSermonRequest allows nullable)
    ];

    $sermonDate = new \DateTime($sermon->date);
    $updateUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}/edit";

    $response = $this->post($updateUrl, $updatedData); // POST to /edit as per form
    $response->assertStatus(302); // Expect redirect

    $sermon->refresh();
    $this->assertNull($sermon->points);

    // Fetch the show page
    $showUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";
    $showResponse = $this->get($showUrl);
    $showResponse->assertStatus(200);
    $showResponse->assertDontSee('Sermon Outline'); // The H2 heading for points section
    $showResponse->assertDontSee('Initial Point'); // Ensure old points are gone

    Auth::logout();
  }

  public function test_update_sermon_with_empty_array_points_does_not_render_points_section()
  {
    Auth::loginUsingId(2); // Authorized user
    $sermon = $this->createTestSermon([
      'points' => json_encode([['point' => 'Another Initial Point']])
    ]);

    $updatedData = [
      'title' => $sermon->title,
      'date' => $sermon->date,
      'service' => $sermon->service,
      'preacher' => $sermon->preacher,
      'points' => '[]', // Send JSON string for empty array
    ];

    $sermonDate = new \DateTime($sermon->date);
    $updateUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}/edit";

    $response = $this->post($updateUrl, $updatedData);
    $response->assertStatus(302);

    $sermon->refresh();
    $this->assertEquals([], $sermon->points); // Expect empty array

    // Fetch the show page
    $showUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";
    $showResponse = $this->get($showUrl);
    $showResponse->assertStatus(200);
    $showResponse->assertDontSee('Sermon Outline');
    $showResponse->assertDontSee('Another Initial Point');

    Auth::logout();
  }

  public function test_edit_sermon_form_pre_populates_date_correctly()
  {
    Auth::loginUsingId(2); // Authorized user
    $testDate = '2023-07-15';
    $sermon = $this->createTestSermon(['date' => $testDate]);

    $sermonDateObj = new \DateTime($sermon->date); // Use the actual sermon date for URL
    $editUrl = "/christ/sermons/" . $sermonDateObj->format('Y') . "/" . $sermonDateObj->format('m') . "/{$sermon->slug}/edit";

    $response = $this->get($editUrl);

    $response->assertStatus(200);
    // Assert that the input field for date contains the correctly formatted date
    // The name of the input is 'date', and its value should be $testDate
    $response->assertSee('<input type="date" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="date" name="date" value="' . $testDate . '">', false); // 'false' to not escape HTML

    Auth::logout();
  }

  public function test_edit_sermon_form_does_not_have_duplicate_breadcrumbs()
  {
    Auth::loginUsingId(2); // Authorized user
    $sermon = $this->createTestSermon();

    $sermonDateObj = new \DateTime($sermon->date);
    $editUrl = "/christ/sermons/" . $sermonDateObj->format('Y') . "/" . $sermonDateObj->format('m') . "/{$sermon->slug}/edit";

    $response = $this->get($editUrl);

    $response->assertStatus(200);
    // Assert that the specific manually added breadcrumb structure is NOT present.
    // We check for a unique string from that old structure.
    // For example, the link to the sermon itself within those old breadcrumbs.
    // The old structure was: Home > Sermons > [Series] > Sermon Title (link) > Edit
    // The link to sermon title was: <a href="/christ/sermons/{year}/{month}/{slug}" ...>{{ $sermon->title }}</a>
    // Let's check if this specific link structure is absent.
    // Note: This is a bit fragile if the layout's breadcrumbs are very similar.
    // A more robust way would be to count occurrences of <nav aria-label="Breadcrumb"> if the layout one is different.
    // The removed breadcrumb had: <nav class="mb-6 text-sm" aria-label="Breadcrumb">
    // Let's assume the layout one might not have "mb-6 text-sm" or be structured differently enough.
    // For this test, let's assert that a fairly unique part of the *removed* structure is gone.
    // The removed structure had specific links like "/christ/sermons/series/{{ Str::slug($sermon->series) }}"
    // and the link to the sermon itself.
    // A simpler check: the removed breadcrumb NAV had specific classes "mb-6 text-sm".
    // If the layout's breadcrumb component (<x-breadcrumbs>) does NOT use "mb-6 text-sm" on its nav, this is a good check.
    // From previous reading of components/breadcrumbs.blade.php, its <nav> only had class "m-6".
    // So, we can assert the absence of "mb-6 text-sm" on a nav with aria-label="Breadcrumb".

    // This assertion is tricky. A simpler way is to ensure a highly specific string from the removed breadcrumbs is gone.
    // The removed breadcrumb had: <a href="/christ/sermons/{{ $sermonYear }}/{{ $sermonMonth }}/{{ $sermon->slug }}" ...>{{ $sermon->title }}</a>
    // Let's verify the *absence* of this specific link structure which was unique to the removed breadcrumbs.
    // The link to the sermon title itself before the "Edit" span.
    $sermonLinkPattern = '/<a href="\/christ\/sermons\/' . $sermonDateObj->format('Y') . '\/' . $sermonDateObj->format('m') . '\/' . $sermon->slug . '"[^>]*>\s*' . preg_quote($sermon->title, '/') . '\s*<\/a>/';
    $this->assertDoesNotMatchRegularExpression($sermonLinkPattern, $response->content());

    // And ensure the main heading "Edit this sermon" (which the layout breadcrumbs might use) is still there.
    $response->assertSee('Edit this sermon');

    Auth::logout();
  }

  public function test_post_sermon_successful_upload_parses_filename_handles_missing_id3()
  {
    Auth::loginUsingId(2); // Authorized user
    Storage::fake('public');

    $originalFilename = 'My Sermon Recording-2024-07-15-pm.mp3';
    $originalFilenameBase = pathinfo($originalFilename, PATHINFO_FILENAME); // 'My Sermon Recording-2024-07-15-pm'
    $file = UploadedFile::fake()->create($originalFilename, 2048, 'audio/mpeg'); // 2MB

    // Assuming 'sermonPost' is the named route for POST /christ/sermons/post
    $response = $this->post(route('sermonPost'), [
      'file' => $file,
    ]);

    $response->assertStatus(302);
    $response->assertRedirect(route('sermonIndex'));
    // With UploadedFile::fake(), getTitle() on GetId3 will likely be null.
    // So, the message should use the fallback 'Sermon'.
    $response->assertSessionHas('message', 'Sermon successfully posted!');

    // Retrieve the created sermon (assuming only one will match these specific conditions)
    // Order by ID desc to get the latest if multiple match some loose criteria.
    $sermon = Sermon::orderBy('id', 'desc')->first();
    $this->assertNotNull($sermon, "Sermon was not created.");

    // 1. Assert file was stored (Storage::putFile generates a unique name)
    Storage::disk('public')->assertExists($sermon->filename);
    $this->assertStringStartsWith('sermons/', $sermon->filename); // Check it's in the sermons directory

    // 2. Assert database record content
    $this->assertEquals('2024-07-15', $sermon->date); // Parsed from filename
    $this->assertEquals('evening', $sermon->service);    // Parsed from filename 'pm'

    // For faked files, ID3 tags will be empty/null
    $this->assertNull($sermon->title, "Title should be null for fake file without ID3 mock");
    $this->assertNull($sermon->series, "Series should be null for fake file without ID3 mock");
    $this->assertNull($sermon->preacher, "Preacher should be null for fake file without ID3 mock");
    $this->assertEquals('', $sermon->reference, "Reference should be empty string for fake file without ID3 mock based on controller logic `\$reference = '';`");

    // Slug should be based on $originalFilenameBase because title is null
    $this->assertEquals(Str::slug($originalFilenameBase), $sermon->slug);

    Auth::logout();
  }

  public function test_post_sermon_fails_for_unauthorized_user()
  {
    // Ensure no admin user is logged in.
    // If a "basic" user factory state exists, could log that user in.
    // For now, test as guest.
    Auth::logout();

    Storage::fake('public');
    $originalFilename = 'AttemptedUpload-2024-07-15-am.mp3';
    $file = UploadedFile::fake()->create($originalFilename, 1024, 'audio/mpeg');

    $initialSermonCount = Sermon::count();

    $response = $this->post(route('sermonPost'), [
      'file' => $file,
    ]);

    $response->assertStatus(403); // Expect Forbidden

    // Assert no new sermon was created
    $this->assertEquals($initialSermonCount, Sermon::count());

    // Assert file was not stored (Storage::putFile would generate a unique name,
    // so we can't easily assertMissing for a specific name without knowing it.
    // Instead, check if the 'sermons' directory is empty or contains unexpected files.
    // A simpler check if we know no files should be there:
    $filesInStorage = Storage::disk('public')->files('sermons');
    $this->assertEmpty($filesInStorage, "No files should have been stored in the 'sermons' directory.");
  }

  public function test_post_sermon_fails_if_file_is_missing()
  {
    Auth::loginUsingId(2); // Authorized user

    // Attempt to POST without a file
    $response = $this->post(route('sermonPost'), [
      // No 'file' key in the data
    ]);

    $response->assertStatus(302); // Expect redirect back due to validation failure
    $response->assertSessionHasErrors('file');
    // Check for a specific message if one is set for 'required' in PostSermonRequest,
    // e.g., $response->assertSessionHasErrors(['file' => 'Please select an MP3 file to upload.']);
    // From PostSermonRequestTest, the message for 'file.required' is 'Please select an MP3 file to upload.'
    $response->assertSessionHasErrors([
      'file' => 'Please select an MP3 file to upload.'
    ]);

    Auth::logout();
  }
}
