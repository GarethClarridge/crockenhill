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
// use App\Models\User; // Assuming this is the User model path - Corrected below
use Crockenhill\Sermon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use Crockenhill\User; // Corrected User model namespace
use Crockenhill\Service;
use Mockery;
use OwenOj\LaravelGetId3\GetId3;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon; // Added missing Carbon import

class SermonTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected Service $morningService;
    protected Service $eveningService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = User::factory()->admin()->create();

        $this->morningService = Service::factory()->create(['name' => 'Morning Service']);
        $this->eveningService = Service::factory()->create(['name' => 'Evening Service']);
    }

    /**
     * Test admin can store sermon via form with points.
     * @test
     * @return void
     */
    public function test_admin_can_store_sermon_via_form_with_points()
    {
        $this->actingAs($this->adminUser);

    Storage::fake('public');

    $file = UploadedFile::fake()->create('test_sermon.mp3', 1024, 'audio/mpeg');

        $sermonData = [
            'title' => 'Test Sermon Title',
            'preacher' => 'Test Preacher',
            'series' => 'Test Series',
            'reference' => 'John 3:16',
            'date' => now()->format('Y-m-d'),
            'service' => 'morning', // This will be converted to service_id by StoreSermonRequest
            'file' => $file,
            'point-1' => 'Main Point 1 Value',
            'sub-point-1-1' => 'Sub Point 1.1 Value',
        ];

        $response = $this->post(route('sermons.store'), $sermonData);

        $response->assertStatus(302);
        $response->assertSessionHas('message', '"Test Sermon Title" successfully uploaded!');
        $response->assertRedirect(route('sermonIndex'));

        $sermon = Sermon::where('title', 'Test Sermon Title')->orderBy('id', 'desc')->first();
        $this->assertNotNull($sermon, 'Sermon was not created in the database.');
        $this->assertInstanceOf(Sermon::class, $sermon);

    Storage::disk('public')->assertExists($sermon->filename);

        $this->assertIsArray($sermon->points);
        $this->assertCount(1, $sermon->points);
        $this->assertArrayHasKey('point', $sermon->points[0]);
        $this->assertEquals('Main Point 1 Value', $sermon->points[0]['point']);
        $this->assertArrayHasKey('sub_points', $sermon->points[0]);
        $this->assertIsArray($sermon->points[0]['sub_points']);
        $this->assertCount(1, $sermon->points[0]['sub_points']);
        $this->assertEquals('Sub Point 1.1 Value', $sermon->points[0]['sub_points'][0]);
    }

    // Helper method to create a sermon for tests
    protected function createTestSermon(array $overrides = []): Sermon
    {
        $defaultServiceId = $this->morningService->id;
        if (isset($overrides['service'])) {
            $defaultServiceId = ($overrides['service'] === 'evening' || $overrides['service'] === $this->eveningService->id)
                ? $this->eveningService->id
                : $this->morningService->id;
            unset($overrides['service']);
        }


        return Sermon::factory()->create(array_merge([
            'service_id' => $defaultServiceId,
        ], $overrides));
    }

    public function test_update_sermon_successful()
    {
        $this->actingAs($this->adminUser);
        $sermon = $this->createTestSermon();

        $updatedData = [ // This array represents the raw data for the model
            'title' => 'Updated Sermon Title',
            'preacher' => 'Updated Preacher',
            'series' => 'Updated Series',
            'reference' => 'Exodus 20:1',
            'date' => now()->format('Y-m-d'),
            'service_id' => $this->eveningService->id, // Use service_id directly for model state
            'points' => json_encode([['point' => 'New Point 1', 'sub_points' => ['New Sub 1.1']]]),
        ];

        // This array represents what would be POSTed from a form.
        // It might use 'service' string if the FormRequest handles conversion.
        $updatedDataForPost = [
            'title' => 'Updated Sermon Title',
            'preacher' => 'Updated Preacher',
            'series' => 'Updated Series',
            'reference' => 'Exodus 20:1',
            'date' => now()->format('Y-m-d'),
            'service' => 'evening', // Form might send string 'evening'
            'points' => json_encode([['point' => 'New Point 1', 'sub_points' => ['New Sub 1.1']]]),
        ];


        $sermonDate = Carbon::parse($sermon->date);
        $updateUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}/edit";
        $response = $this->post($updateUrl, $updatedDataForPost);

        $response->assertStatus(302);
        $response->assertRedirect(route('sermonIndex'));
        $response->assertSessionHas('message', '"Updated Sermon Title" successfully updated!');

        $sermon->refresh();
        $this->assertEquals('Updated Sermon Title', $sermon->title);
        $this->assertEquals('Updated Preacher', $sermon->preacher);
        $this->assertEquals([['point' => 'New Point 1', 'sub_points' => ['New Sub 1.1']]], $sermon->points);
        $this->assertEquals(Str::slug('Updated Sermon Title'), $sermon->slug);
        $this->assertEquals($this->eveningService->id, $sermon->service_id); // Assert the ID


        $showUrl = "/christ/sermons/" . Carbon::parse($sermon->date)->format('Y') . "/" . Carbon::parse($sermon->date)->format('m') . "/{$sermon->slug}";
        $showResponse = $this->get($showUrl);
        $showResponse->assertOk();
        $showResponse->assertSee('New Point 1');
        $showResponse->assertSee('New Sub 1.1');
    }

    public function test_update_sermon_validation_failures()
    {
        $this->actingAs($this->adminUser);
        $sermon = $this->createTestSermon();
        $sermonDate = Carbon::parse($sermon->date);
        $updateUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}/edit";

        $response = $this->post($updateUrl, ['title' => '', 'date' => $sermon->date, 'service_id' => $sermon->service_id, 'preacher' => $sermon->preacher]);
        $response->assertStatus(302);
        $response->assertSessionHasErrors('title');

        $response = $this->post($updateUrl, ['title' => 'Valid Title Update', 'date' => $sermon->date, 'service_id' => $sermon->service_id, 'preacher' => $sermon->preacher, 'points' => 'this is not json']);
        $response->assertStatus(302);
        $response->assertSessionHasErrors('points');
    }

    public function test_update_sermon_unauthorized_user()
    {
        $sermon = $this->createTestSermon();
        $sermonDate = Carbon::parse($sermon->date);
        $updateUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}/edit";


        $response = $this->post(
            $updateUrl,
            ['title' => 'Attempted Update', 'date' => $sermon->date, 'service_id' => $sermon->service_id, 'preacher' => $sermon->preacher]
        );
        $response->assertRedirect(route('login'));
    }

    public function test_update_sermon_not_found()
    {
        $this->actingAs($this->adminUser);
        $response = $this->post(
            "/christ/sermons/1900/01/non-existent-slug/edit",
            ['title' => 'Update Non Existent', 'date' => '1900-01-01', 'service_id' => $this->morningService->id, 'preacher' => 'Test']
        );
        $response->assertStatus(404);
    }

    public function test_store_sermon_fails_for_unauthorized_user()
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->create('test_sermon.mp3', 100, 'audio/mpeg');

    $sermonData = [
      'title' => 'Attempted Sermon Title',
      'preacher' => 'Test Preacher',
      'date' => now()->format('Y-m-d'),
      'service' => 'morning',
      'file' => $file,
    ];

        $response = $this->post(route('sermons.store'), $sermonData);
        $response->assertStatus(403);
        Storage::disk('public')->assertMissing('sermons/' . $file->hashName());
    }

    public function test_store_sermon_validation_failures()
    {
        $this->actingAs($this->adminUser); // Standardized to use actingAs

    Storage::fake('public');

        $response_missing_title = $this->post(route('sermons.store'), [
            'file' => UploadedFile::fake()->create('sermon.mp3', 100),
            'date' => '2024-01-01',
            'service_id' => $this->morningService->id,
            'preacher' => 'Test Preacher',
        ]);
        $response_missing_title->assertStatus(302);
        $response_missing_title->assertSessionHasErrors('title');

        $largeFile = UploadedFile::fake()->create('large_sermon.mp3', 60000, 'audio/mpeg');
        $response_file_too_large = $this->post(route('sermons.store'), [
            'title' => 'Sermon with Large File',
            'file' => $largeFile,
            'date' => '2024-01-01',
            'service_id' => $this->morningService->id,
            'preacher' => 'Test Preacher',
        ]);
        $response_file_too_large->assertStatus(302);
        $response_file_too_large->assertSessionHasErrors('file');
        Storage::disk('public')->assertMissing('sermons/' . $largeFile->hashName());


        $response_invalid_date = $this->post(route('sermons.store'), [
            'title' => 'Sermon with Invalid Date',
            'file' => UploadedFile::fake()->create('sermon.mp3', 100),
            'date' => '01/01/2024',
            'service_id' => $this->morningService->id,
            'preacher' => 'Test Preacher',
        ]);
        $response_invalid_date->assertStatus(302);
        $response_invalid_date->assertSessionHasErrors('date');

        $response_missing_file = $this->post(route('sermons.store'), [
            'title' => 'Sermon Missing File',
            'date' => '2024-01-01',
            'service_id' => $this->morningService->id,
            'preacher' => 'Test Preacher',
        ]);
        $response_missing_file->assertStatus(302);
        $response_missing_file->assertSessionHasErrors('file');
    }

    public function test_destroy_sermon_successful()
    {
        $this->actingAs($this->adminUser);
        $sermon = $this->createTestSermon(['filename' => 'sermons/testfile_to_delete.mp3']); // Ensure filename is set
        Storage::fake('public');
        Storage::disk('public')->put($sermon->filename, 'dummy content'); // Create a fake file

        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]);
        Storage::disk('public')->assertExists($sermon->filename);


        $sermonDate = Carbon::parse($sermon->date);
        $deleteUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";

    $response = $this->delete($deleteUrl);

        $response->assertStatus(302);
        $response->assertRedirect(route('sermonIndex'));
        $response->assertSessionHas('message', 'Sermon successfully deleted!');

        $this->assertDatabaseMissing('sermons', ['id' => $sermon->id]);
        // Per subtask, assert file is NOT deleted by current controller logic
        Storage::disk('public')->assertExists($sermon->filename);
    }

    public function test_destroy_sermon_unauthorized_user()
    {
        $sermon = $this->createTestSermon();
        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]);

        $sermonDate = Carbon::parse($sermon->date);
        $deleteUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";

        $response = $this->delete($deleteUrl);
        $response->assertRedirect(route('login'));
        $this->assertDatabaseHas('sermons', ['id' => $sermon->id]);
    }

    public function test_destroy_sermon_not_found()
    {
        $this->actingAs($this->adminUser);

    $deleteUrl = "/christ/sermons/1900/01/non-existent-slug";
    $response = $this->delete($deleteUrl);

        $response->assertStatus(404);
    }

    public function test_show_sermon_page_loads_for_existing_sermon()
    {
        $sermon = $this->createTestSermon(['title' => 'Specific Sermon Title for Show Test']);
        $sermonDate = Carbon::parse($sermon->date);
        $showUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";

    $response = $this->get($showUrl);

        $response->assertOk();
        $response->assertSee('Specific Sermon Title for Show Test');
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
        $sermon = $this->createTestSermon(['title' => 'Latest Sermon on Index']);

        $response = $this->get(route('sermonIndex'));
        $response->assertOk();
        $response->assertSee('Latest Sermon on Index');
    }

    public function test_sermon_all_page_loads()
    {
        $sermon = $this->createTestSermon(['title' => 'Sermon for All Page']);
        $response = $this->get('/sermons/all');
        $response->assertOk();
        $response->assertSee('Sermon for All Page');
    }

    public function test_sermon_preachers_page_loads_and_shows_preacher()
    {
        $this->createTestSermon(['preacher' => 'Test Preacher Name']);
        $response = $this->get('/christ/sermons/preachers');
        $response->assertOk();
        $response->assertSee('Test Preacher Name');
    }

    public function test_sermon_serieses_page_loads_and_shows_series()
    {
        $this->createTestSermon(['series' => 'Test Series Name']);
        $response = $this->get('/christ/sermons/series');
        $response->assertOk();
        $response->assertSee('Test Series Name');
    }

  public function test_single_preacher_page_loads_and_shows_sermon()
  {
    $preacherName = 'John Doe';
    $preacherSlug = Str::slug($preacherName);
    $sermon = $this->createTestSermon(['preacher' => $preacherName, 'title' => 'Sermon by John Doe']);

        $response = $this->get("/christ/sermons/preachers/{$preacherSlug}");
        $response->assertOk();
        $response->assertSee('Sermon by John Doe');
    }

  public function test_single_series_page_loads_and_shows_sermon()
  {
    $seriesName = 'Studies in Genesis';
    $seriesSlug = Str::slug($seriesName);
    $sermon = $this->createTestSermon(['series' => $seriesName, 'title' => 'Genesis Sermon 1']);

        $response = $this->get("/christ/sermons/series/{$seriesSlug}");
        $response->assertOk();
        $response->assertSee('Genesis Sermon 1');
    }

    public function test_single_service_page_loads_and_shows_sermon()
    {
        $sermon = $this->createTestSermon([
            'service_id' => $this->morningService->id,
            'title' => 'Morning Service Sermon'
        ]);

        $response = $this->get("/christ/sermons/service/morning");
        $response->assertOk();
        $response->assertSee('Morning Service Sermon');
    }

    public function test_update_sermon_with_null_points_does_not_render_points_section()
    {
        $this->actingAs($this->adminUser);
        $sermon = $this->createTestSermon([
            'points' => json_encode([['point' => 'Initial Point', 'sub_points' => ['Initial Sub']]])
        ]);

        $updatedData = [
            'title' => $sermon->title,
            'date' => $sermon->date,
            'service_id' => $sermon->service_id,
            'preacher' => $sermon->preacher,
            'points' => null,
        ];

        $sermonDate = Carbon::parse($sermon->date);
        $updateUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}/edit";

        $response = $this->post($updateUrl, $updatedData);
        $response->assertStatus(302);

    $sermon->refresh();
    $this->assertNull($sermon->points);

        $showUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";
        $showResponse = $this->get($showUrl);
        $showResponse->assertOk();
        $showResponse->assertDontSee('Sermon Outline');
        $showResponse->assertDontSee('Initial Point');
    }

    public function test_update_sermon_with_empty_array_points_does_not_render_points_section()
    {
        $this->actingAs($this->adminUser);
        $sermon = $this->createTestSermon([
            'points' => json_encode([['point' => 'Another Initial Point']])
        ]);

        $updatedData = [
            'title' => $sermon->title,
            'date' => $sermon->date,
            'service_id' => $sermon->service_id,
            'preacher' => $sermon->preacher,
            'points' => '[]',
        ];

        $sermonDate = Carbon::parse($sermon->date);
        $updateUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}/edit";

    $response = $this->post($updateUrl, $updatedData);
    $response->assertStatus(302);

        $sermon->refresh();
        $this->assertEquals([], $sermon->points);

        $showUrl = "/christ/sermons/" . $sermonDate->format('Y') . "/" . $sermonDate->format('m') . "/{$sermon->slug}";
        $showResponse = $this->get($showUrl);
        $showResponse->assertOk();
        $showResponse->assertDontSee('Sermon Outline');
        $showResponse->assertDontSee('Another Initial Point');
    }

    public function test_edit_sermon_form_pre_populates_date_correctly()
    {
        $this->actingAs($this->adminUser);
        $testDate = '2023-07-15';
        $sermon = $this->createTestSermon(['date' => Carbon::parse($testDate)]);

        $sermonDateObj = Carbon::parse($sermon->date);
        $editUrl = "/christ/sermons/" . $sermonDateObj->format('Y') . "/" . $sermonDateObj->format('m') . "/{$sermon->slug}/edit";

    $response = $this->get($editUrl);

        $response->assertOk();
        $response->assertSee('<input type="date" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="date" name="date" value="' . $testDate . '">', false);
    }

    public function test_edit_sermon_form_does_not_have_duplicate_breadcrumbs()
    {
        $this->actingAs($this->adminUser);
        $sermon = $this->createTestSermon();

        $sermonDateObj = Carbon::parse($sermon->date);
        $editUrl = "/christ/sermons/" . $sermonDateObj->format('Y') . "/" . $sermonDateObj->format('m') . "/{$sermon->slug}/edit";

    $response = $this->get($editUrl);

        $response->assertOk();
        $sermonLinkPattern = '/<a href="\/christ\/sermons\/' . $sermonDateObj->format('Y') . '\/' . $sermonDateObj->format('m') . '\/' . $sermon->slug . '"[^>]*>\s*' . preg_quote($sermon->title, '/') . '\s*<\/a>/';
        $this->assertDoesNotMatchRegularExpression($sermonLinkPattern, $response->content());

        $response->assertSee('Edit this sermon');
    }

    public function test_post_sermon_successful_upload_parses_filename_handles_missing_id3()
    {
        $this->actingAs($this->adminUser);
        Storage::fake('public');

        $originalFilename = 'My Sermon Recording-2024-07-15-pm.mp3';
        $originalFilenameBase = pathinfo($originalFilename, PATHINFO_FILENAME);
        $file = UploadedFile::fake()->create($originalFilename, 2048, 'audio/mpeg');

        $response = $this->post(route('sermonPost'), [
            'file' => $file,
        ]);

        $response->assertStatus(302);
        $response->assertRedirect(route('sermonIndex'));
        $response->assertSessionHas('message', 'Sermon successfully posted!');

        $sermon = Sermon::orderBy('id', 'desc')->first();
        $this->assertNotNull($sermon, "Sermon was not created.");

        Storage::disk('public')->assertExists($sermon->filename);
        $this->assertStringStartsWith('sermons/', $sermon->filename);

        $this->assertEquals('2024-07-15', Carbon::parse($sermon->date)->format('Y-m-d'));
        $this->assertEquals($this->eveningService->id, $sermon->service_id);

        $this->assertNull($sermon->title);
        $this->assertNull($sermon->series);
        $this->assertNull($sermon->preacher);
        $this->assertEquals('', $sermon->reference);

        $this->assertEquals(Str::slug($originalFilenameBase), $sermon->slug);
    }

    public function test_post_sermon_fails_for_unauthorized_user()
    {
        Storage::fake('public');
        $originalFilename = 'AttemptedUpload-2024-07-15-am.mp3';
        $file = UploadedFile::fake()->create($originalFilename, 1024, 'audio/mpeg');

    $initialSermonCount = Sermon::count();

        $response = $this->post(route('sermonPost'), [
            'file' => $file,
        ]);
        $response->assertRedirect(route('login'));

        $this->assertEquals($initialSermonCount, Sermon::count());
        $filesInStorage = Storage::disk('public')->files('sermons');
        $this->assertEmpty($filesInStorage);
    }

    public function test_post_sermon_fails_if_file_is_missing()
    {
        $this->actingAs($this->adminUser);

        $response = $this->post(route('sermonPost'), []);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('file');
        $response->assertSessionHasErrors([
            'file' => 'Please select an MP3 file to upload.'
        ]);
    }

    /**
     * @test
     * @dataProvider sermonFilenameProvider
     */
    public function post_sermon_parses_date_and_service_from_filename_correctly(
        string $filename,
        string $expectedDate,
        string $expectedServiceType,
        ?string $expectedTitlePartFromFilename // This param isn't used in assertions currently, can be removed if not needed for title logic
    ) {
        $this->actingAs($this->adminUser);
        Storage::fake('public');

        $file = UploadedFile::fake()->create($filename, 1024, 'audio/mpeg');

        $response = $this->post(route('sermonPost'), ['file' => $file]);

        $response->assertRedirect(route('sermonIndex'));
        $response->assertSessionHas('message', 'Sermon successfully posted!');

        $sermon = Sermon::orderBy('id', 'desc')->first();
        $this->assertNotNull($sermon);

        $this->assertEquals($expectedDate, Carbon::parse($sermon->date)->format('Y-m-d'));

        $expectedServiceId = ($expectedServiceType === 'evening') ? $this->eveningService->id : $this->morningService->id;
        $this->assertEquals($expectedServiceId, $sermon->service_id);

        $this->assertNull($sermon->title);

        $expectedSlugBase = pathinfo($filename, PATHINFO_FILENAME);
        $this->assertEquals(Str::slug($expectedSlugBase), $sermon->slug);
    }

    public static function sermonFilenameProvider(): array
    {
        return [
            'Typical AM' => ['Sermon Title-2023-01-15-am.mp3', '2023-01-15', 'morning', 'Sermon Title'],
            'Typical PM' => ['Another Name-2023-02-20-pm.mp3', '2023-02-20', 'evening', 'Another Name'],
            'Date First AM' => ['2023-03-10-am-Sermon One.mp3', '2023-03-10', 'morning', 'Sermon One'],
            'No Dash Date AM short' => ['20230412am Sermon Two.mp3', '2023-04-12', 'morning', 'Sermon Two'],
            'No Dash Date PM uppercase' => ['My Sermon-20230525-PM.mp3', '2023-05-25', 'evening', 'My Sermon'],
            'YYYYMMDD directly followed by am/pm' => ['Title-20230630pm.mp3', '2023-06-30', 'evening', 'Title'],
            'AM in caps' => ['Caps AM Test-2023-07-01-AM.mp3', '2023-07-01', 'morning', 'Caps AM Test'],
            'Date at end' => ['Other Title-am-2023-08-15.mp3', '2023-08-15', 'morning', 'Other Title'],
        ];
    }

    /** @test */
    public function post_sermon_handles_filenames_with_missing_date_or_service_info()
    {
        $this->actingAs($this->adminUser);
        Storage::fake('public');
        $today = Carbon::now()->format('Y-m-d');

        $fileMissingDate = UploadedFile::fake()->create('Sermon Without Date-am.mp3', 1024, 'audio/mpeg');
        $this->post(route('sermonPost'), ['file' => $fileMissingDate]);
        $sermonMissingDate = Sermon::orderBy('id', 'desc')->first();
        $this->assertNotNull($sermonMissingDate);
        $this->assertEquals($today, Carbon::parse($sermonMissingDate->date)->format('Y-m-d'));
        $this->assertEquals($this->morningService->id, $sermonMissingDate->service_id);

        Sermon::query()->delete();
        $fileMissingService = UploadedFile::fake()->create('Sermon Without Service-2023-09-10.mp3', 1024, 'audio/mpeg');
        $this->post(route('sermonPost'), ['file' => $fileMissingService]);
        $sermonMissingService = Sermon::orderBy('id', 'desc')->first();
        $this->assertNotNull($sermonMissingService);
        $this->assertEquals('2023-09-10', Carbon::parse($sermonMissingService->date)->format('Y-m-d'));
        $this->assertEquals($this->morningService->id, $sermonMissingService->service_id);

        Sermon::query()->delete();
        $fileJustTitle = UploadedFile::fake()->create('Just A Title.mp3', 1024, 'audio/mpeg');
        $this->post(route('sermonPost'), ['file' => $fileJustTitle]);
        $sermonJustTitle = Sermon::orderBy('id', 'desc')->first();
        $this->assertNotNull($sermonJustTitle);
        $this->assertEquals($today, Carbon::parse($sermonJustTitle->date)->format('Y-m-d'));
        $this->assertEquals($this->morningService->id, $sermonJustTitle->service_id);
    }

    /** @test */
    public function post_sermon_populates_fields_from_id3_tags_when_available()
    {
        $this->actingAs($this->adminUser);
        Storage::fake('public');

        // GetId3 is instantiated directly in PostSermonRequest: new GetId3($this->file->path())
        // This test highlights that PostSermonRequest should be refactored for DI.
        $this->markTestIncomplete('Cannot mock GetId3: PostSermonRequest uses "new GetId3()". Refactor for DI needed. This test will pass through to filename parsing if GetId3 fails to extract tags from a fake file.');

        // HYPOTHETICAL TEST LOGIC IF MOCKING WAS POSSIBLE:
        // $expectedId3Data = [
        //     'title' => 'Sermon Title From ID3',
        //     'artist' => 'Rev. ID3 Preacher',
        //     'album' => 'ID3 Series Name',
        //     'comment' => 'John 3:16 from ID3',
        // ];
        // $id3AnalysisMock = Mockery::mock();
        // $id3AnalysisMock->shouldReceive('getTitle')->andReturn($expectedId3Data['title']);
        // $id3AnalysisMock->shouldReceive('getArtist')->andReturn($expectedId3Data['artist']);
        // $id3AnalysisMock->shouldReceive('getAlbum')->andReturn($expectedId3Data['album']);
        // $id3AnalysisMock->shouldReceive('getComment')->andReturn($expectedId3Data['comment']);
        // $this->instance(GetId3::class, $id3AnalysisMock); // This would require GetId3 to be resolved via container

        // $filename = 'AudioFile-2023-10-01-am.mp3';
        // $file = UploadedFile::fake()->create($filename, 1024, 'audio/mpeg');
        // $response = $this->post(route('sermonPost'), ['file' => $file]);
        // $response->assertRedirect(route('sermonIndex'));
        // $response->assertSessionHas('message', '"' . $expectedId3Data['title'] . '" successfully posted!');
        // $sermon = Sermon::orderBy('id', 'desc')->first();
        // $this->assertNotNull($sermon);
        // $this->assertEquals($expectedId3Data['title'], $sermon->title);
        // $this->assertEquals($expectedId3Data['artist'], $sermon->preacher);
        // $this->assertEquals($expectedId3Data['album'], $sermon->series);
        // $this->assertEquals($expectedId3Data['comment'], $sermon->reference);
        // $this->assertEquals('2023-10-01', Carbon::parse($sermon->date)->format('Y-m-d'));
        // $this->assertEquals($this->morningService->id, $sermon->service_id);
        // $this->assertEquals(Str::slug($expectedId3Data['title']), $sermon->slug);
    }

    /** @test */
    public function post_sermon_uses_filename_parsing_as_fallback_if_all_id3_tags_are_missing()
    {
        $this->actingAs($this->adminUser);
        Storage::fake('public');

        // This test is similar to test_post_sermon_successful_upload_parses_filename_handles_missing_id3
        // but would ideally ensure through mocking that GetId3 returns nulls.
        // Since mocking `new GetId3()` is problematic without SUT refactor, this test will currently
        // behave identically to test_post_sermon_successful_upload_parses_filename_handles_missing_id3
        // as UploadedFile::fake() doesn't produce ID3 tags that the GetId3 library can read.
        // Marking as incomplete for the same reasons.
        $this->markTestIncomplete('Cannot effectively mock GetId3 to ensure nulls: PostSermonRequest uses "new GetId3()". Refactor for DI needed. This test currently verifies behavior when GetId3 does not find tags in a fake file.');

        // HYPOTHETICAL TEST LOGIC IF MOCKING WAS POSSIBLE:
        // $id3AnalysisNoTagsMock = Mockery::mock(); // Mock the result of analyze()
        // $id3AnalysisNoTagsMock->shouldReceive('getTitle')->andReturnNull();
        // $id3AnalysisNoTagsMock->shouldReceive('getArtist')->andReturnNull();
        // $id3AnalysisNoTagsMock->shouldReceive('getAlbum')->andReturnNull();
        // $id3AnalysisNoTagsMock->shouldReceive('getComment')->andReturnNull();
        // Mock GetId3 to return this $id3AnalysisNoTagsMock
        // $this->instance(GetId3::class, $someMockThatReturnsId3AnalysisNoTagsMock);

        // $filename = 'Filename Sermon-2023-11-05-pm.mp3';
        // $file = UploadedFile::fake()->create($filename, 1024, 'audio/mpeg');
        // $this->post(route('sermonPost'), ['file' => $file]);

        // $sermon = Sermon::orderBy('id', 'desc')->first();
        // $this->assertNotNull($sermon);
        // $this->assertNull($sermon->title);
        // $this->assertNull($sermon->preacher);
        // $this->assertNull($sermon->series);
        // $this->assertEquals('', $sermon->reference);
        // $this->assertEquals('2023-11-05', Carbon::parse($sermon->date)->format('Y-m-d'));
        // $this->assertEquals($this->eveningService->id, $sermon->service_id);
        // $this->assertEquals(Str::slug(pathinfo($filename, PATHINFO_FILENAME)), $sermon->slug);
    }

    /** @test */
    public function post_sermon_uses_partial_id3_tags_and_filename_fallbacks()
    {
        $this->actingAs($this->adminUser);
        Storage::fake('public');

        // Marking as incomplete for the same reasons as above.
        $this->markTestIncomplete('Cannot effectively mock GetId3 for partial data: PostSermonRequest uses "new GetId3()". Refactor for DI needed. This test currently verifies behavior when GetId3 does not find tags in a fake file.');

        // HYPOTHETICAL TEST LOGIC IF MOCKING WAS POSSIBLE:
        // $id3AnalysisPartialMock = Mockery::mock(); // Mock the result of analyze()
        // $id3AnalysisPartialMock->shouldReceive('getTitle')->andReturn('ID3 Title Present');
        // $id3AnalysisPartialMock->shouldReceive('getArtist')->andReturnNull();
        // $id3AnalysisPartialMock->shouldReceive('getAlbum')->andReturn('ID3 Album Present');
        // $id3AnalysisPartialMock->shouldReceive('getComment')->andReturnNull();
        // Mock GetId3 to return this $id3AnalysisPartialMock
        // $this->instance(GetId3::class, $someMockThatReturnsId3AnalysisPartialMock);

        // $filename = 'Partial ID3 Info-2023-12-10-am.mp3';
        // $file = UploadedFile::fake()->create($filename, 1024, 'audio/mpeg');
        // $this->post(route('sermonPost'), ['file' => $file]);

        // $sermon = Sermon::orderBy('id', 'desc')->first();
        // $this->assertNotNull($sermon);
        // $this->assertEquals('ID3 Title Present', $sermon->title);
        // $this->assertNull($sermon->preacher);
        // $this->assertEquals('ID3 Album Present', $sermon->series);
        // $this->assertEquals('', $sermon->reference);
        // $this->assertEquals('2023-12-10', Carbon::parse($sermon->date)->format('Y-m-d'));
        // $this->assertEquals($this->morningService->id, $sermon->service_id);
        // $this->assertEquals(Str::slug('ID3 Title Present'), $sermon->slug);
    }
}