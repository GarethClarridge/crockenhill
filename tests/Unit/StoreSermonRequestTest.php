<?php

namespace Tests\Unit;

use Tests\TestCase; // Or your project's base test case
use Crockenhill\Http\Requests\StoreSermonRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
// Assuming User model is App\Models\User for Gate simulation if needed, though direct Gate mocking is better
// use App\Models\User;

class StoreSermonRequestTest extends TestCase
{
    private StoreSermonRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new StoreSermonRequest();
    }

    public function test_authorize_allows_user_with_edit_sermons_permission()
    {
        Gate::shouldReceive('allows')
            ->once()
            ->with('edit-sermons')
            ->andReturn(true);

        $this->assertTrue($this->request->authorize());
    }

    public function test_authorize_denies_user_without_edit_sermons_permission()
    {
        Gate::shouldReceive('allows')
            ->once()
            ->with('edit-sermons')
            ->andReturn(false);

        $this->assertFalse($this->request->authorize());
    }

    #[DataProvider('validationDataProvider')]
    public function test_validation_rules(array $data, bool $shouldPass, array $expectedErrors = [])
    {
        $validator = Validator::make($data, $this->request->rules(), $this->request->messages());

        $this->assertEquals($shouldPass, $validator->passes(),
            $shouldPass ? 'Validation should have passed but failed.' : 'Validation should have failed but passed. Errors: ' . print_r($validator->errors()->toArray(), true)
        );

        if (!$shouldPass && !empty($expectedErrors)) {
            foreach ($expectedErrors as $field => $messageFragment) {
                $this->assertTrue($validator->errors()->has($field), "Expected error for field '{$field}' but none found.");
                if ($messageFragment) { // Some rules might not have specific messages, just presence of error
                     $this->assertStringContainsString(
                         $messageFragment,
                         implode(' ', $validator->errors()->get($field)),
                         "Field '{$field}' did not contain expected error message fragment '{$messageFragment}'."
                     );
                }
            }
        }
    }

    public static function validationDataProvider(): array
    {
        // Path for a dummy file for testing uploads
        $dummyMp3 = UploadedFile::fake()->create('dummy.mp3', 100, 'audio/mpeg'); // 100KB MP3
        $largeFile = UploadedFile::fake()->create('large.mp3', 60000, 'audio/mpeg'); // 60MB MP3
        $notMp3 = UploadedFile::fake()->create('dummy.txt', 100, 'text/plain');

        return [
            'all_valid_data' => [
                'data' => [
                    'title' => 'Valid Title',
                    'file' => $dummyMp3,
                    'date' => '2024-01-01',
                    'service' => 'morning',
                    'series' => 'Valid Series',
                    'reference' => 'John 1:1',
                    'preacher' => 'Valid Preacher',
                ],
                'shouldPass' => true,
            ],
            'nullable_fields_empty' => [ // Test with nullable fields being empty
                'data' => [
                    'title' => 'Valid Title',
                    'file' => $dummyMp3,
                    'date' => '2024-01-01',
                    'service' => 'evening',
                    'series' => null,
                    'reference' => null,
                    'preacher' => 'Valid Preacher',
                ],
                'shouldPass' => true
            ],

            // Title validation
            'title_missing' => [['file' => $dummyMp3, 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['title' => 'required']],
            'title_too_long' => [['title' => str_repeat('a', 256), 'file' => $dummyMp3, 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['title' => '255 characters']],

            // File validation
            'file_missing' => [['title' => 'VT', 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['file' => 'required']],
            'file_not_mp3' => [['title' => 'VT', 'file' => $notMp3, 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['file' => 'must be an MP3']],
            'file_too_large' => [['title' => 'VT', 'file' => $largeFile, 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['file' => '50MB']],

            // Date validation
            'date_missing' => [['title' => 'VT', 'file' => $dummyMp3, 'service' => 'morning', 'preacher' => 'VP'], false, ['date' => 'required']],
            'date_invalid_format' => [['title' => 'VT', 'file' => $dummyMp3, 'date' => '01/01/2024', 'service' => 'morning', 'preacher' => 'VP'], false, ['date' => 'Y-m-d']],

            // Service validation
            'service_missing' => [['title' => 'VT', 'file' => $dummyMp3, 'date' => '2024-01-01', 'preacher' => 'VP'], false, ['service' => 'required']],
            'service_invalid_value' => [['title' => 'VT', 'file' => $dummyMp3, 'date' => '2024-01-01', 'service' => 'special', 'preacher' => 'VP'], false, ['service' => 'valid service']],

            // Series validation (nullable, so only check max length if provided)
            'series_too_long' => [['title' => 'VT', 'file' => $dummyMp3, 'date' => '2024-01-01', 'service' => 'morning', 'series' => str_repeat('b', 256), 'preacher' => 'VP'], false, ['series' => '255 characters']],

            // Reference validation (nullable)
            'reference_too_long' => [['title' => 'VT', 'file' => $dummyMp3, 'date' => '2024-01-01', 'service' => 'morning', 'reference' => str_repeat('c', 256), 'preacher' => 'VP'], false, ['reference' => '255 characters']],

            // Preacher validation
            'preacher_missing' => [['title' => 'VT', 'file' => $dummyMp3, 'date' => '2024-01-01', 'service' => 'morning'], false, ['preacher' => 'required']],
            'preacher_too_long' => [['title' => 'VT', 'file' => $dummyMp3, 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => str_repeat('d', 256)], false, ['preacher' => '255 characters']],
        ];
    }
}
