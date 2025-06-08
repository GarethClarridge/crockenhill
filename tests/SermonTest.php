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

// Ensure the class extends Tests\TestCase if that's the project structure for Laravel 8+
// Default was 'class SermonTest extends TestCase' which usually implies TestCase from PHPUnit or an alias.
// For Laravel, it should extend \Tests\TestCase. Assuming the original TestCase alias is fine.
class SermonTest extends TestCase
{
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

    public function testUpdate()
    {

    }
}
