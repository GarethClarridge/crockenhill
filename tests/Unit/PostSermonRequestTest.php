<?php

namespace Tests\Unit;

use Tests\TestCase;
use Crockenhill\Http\Requests\PostSermonRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;

class PostSermonRequestTest extends TestCase
{
    private PostSermonRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new PostSermonRequest();
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
                if ($messageFragment) {
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
        $dummyMp3 = UploadedFile::fake()->create('dummy.mp3', 100, 'audio/mpeg'); // 100KB MP3
        $largeFile = UploadedFile::fake()->create('large.mp3', 60000, 'audio/mpeg'); // 60MB MP3 (max is 51200KB)
        $notMp3 = UploadedFile::fake()->create('dummy.txt', 100, 'text/plain');

        return [
            'valid_file' => [
                'data' => ['file' => $dummyMp3],
                'shouldPass' => true,
            ],
            'file_missing' => [
                'data' => [],
                'shouldPass' => false,
                'expectedErrors' => ['file' => 'Please select an MP3 file'] // Or "required" if message not customized for required specifically
            ],
            'file_not_mp3' => [
                'data' => ['file' => $notMp3],
                'shouldPass' => false,
                'expectedErrors' => ['file' => 'must be an MP3']
            ],
            'file_too_large' => [
                'data' => ['file' => $largeFile],
                'shouldPass' => false,
                'expectedErrors' => ['file' => '50MB']
            ],
            'file_is_not_a_file' => [ // Test case for when 'file' is not an UploadedFile instance
                'data' => ['file' => 'not_a_file_just_a_string'],
                'shouldPass' => false,
                'expectedErrors' => ['file' => 'must be a file'] // Laravel's default 'file' rule message
            ],
        ];
    }
}
