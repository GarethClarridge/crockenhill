<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\ProcessMediaRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProcessMediaRequestTest extends TestCase
{
    private function makeRequest(array $data): ProcessMediaRequest
    {
        $request = ProcessMediaRequest::create('/', 'POST', $data);
        $request->setContainer($this->app);

        return $request;
    }

    #[Test]
    public function type_is_required(): void
    {
        $request = $this->makeRequest([]);
        $validator = Validator::make([], $request->rules(), $request->messages());

        $this->assertTrue($validator->errors()->has('type'));
    }

    #[Test]
    public function invalid_type_fails_validation(): void
    {
        $request = $this->makeRequest(['type' => 'invalid']);
        $validator = Validator::make(['type' => 'invalid'], $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('type'));
    }

    #[Test]
    public function non_string_type_falls_back_to_basic_file_rule_without_throwing(): void
    {
        $request = $this->makeRequest(['type' => ['audio']]);
        $rules = $request->rules();
        $messages = $request->messages();

        $this->assertSame('required|file', $rules['file']);
        $this->assertStringContainsString('100MB', $messages['file.max']);
        $this->assertStringContainsString('MP3', $messages['file.mimes']);
    }

    #[Test]
    public function valid_audio_type_enforces_audio_rules(): void
    {
        $request = $this->makeRequest(['type' => 'audio']);
        $rules = $request->rules();

        $this->assertArrayHasKey('file', $rules);
        $this->assertStringContainsString('mp3', $rules['file']);
    }

    #[Test]
    public function valid_video_type_enforces_video_rules(): void
    {
        $request = $this->makeRequest(['type' => 'video']);
        $rules = $request->rules();

        $this->assertArrayHasKey('file', $rules);
        $this->assertStringContainsString('mp4', $rules['file']);
        // Video has a 1GB limit so max KB should be much larger than audio
        $audioRequest = $this->makeRequest(['type' => 'audio']);
        preg_match('/max:(\d+)/', $rules['file'], $videoMatch);
        preg_match('/max:(\d+)/', $audioRequest->rules()['file'], $audioMatch);
        $this->assertGreaterThan((int) $audioMatch[1], (int) $videoMatch[1]);
    }

    #[Test]
    public function valid_livestream_type_enforces_livestream_rules(): void
    {
        $request = $this->makeRequest(['type' => 'livestream']);
        $rules = $request->rules();

        $this->assertArrayHasKey('file', $rules);
        $this->assertStringContainsString('webm', $rules['file']);
    }

    #[Test]
    public function unknown_type_falls_back_to_basic_file_rule(): void
    {
        $request = $this->makeRequest(['type' => 'unknown']);
        $rules = $request->rules();

        $this->assertArrayHasKey('file', $rules);
        $this->assertEquals('required|file', $rules['file']);
    }

    #[Test]
    public function messages_contain_type_specific_max_size_for_audio(): void
    {
        $request = $this->makeRequest(['type' => 'audio']);
        $messages = $request->messages();

        $this->assertStringContainsString('100.00 MB', $messages['file.max']);
    }

    #[Test]
    public function messages_contain_type_specific_extensions_for_audio(): void
    {
        $request = $this->makeRequest(['type' => 'audio']);
        $messages = $request->messages();

        $this->assertStringContainsString('MP3', $messages['file.mimes']);
    }

    #[Test]
    public function messages_fall_back_to_defaults_for_invalid_type(): void
    {
        $request = $this->makeRequest(['type' => 'invalid']);
        $messages = $request->messages();

        $this->assertStringContainsString('100MB', $messages['file.max']);
        $this->assertStringContainsString('MP3', $messages['file.mimes']);
    }

    #[Test]
    public function audio_file_with_wrong_extension_fails_validation(): void
    {
        $request = $this->makeRequest(['type' => 'audio']);
        $data = [
            'type' => 'audio',
            'file' => UploadedFile::fake()->create('sermon.txt', 1024, 'text/plain'),
        ];
        $validator = Validator::make($data, $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('file'));
    }

    #[Test]
    public function route_type_and_body_type_use_the_same_upload_rules_and_messages(): void
    {
        $adminRequest = $this->makeRequest(['type' => 'video']);
        $apiRequest = ProcessMediaRequest::create('/api/media/video', 'POST', []);
        $apiRequest->setContainer($this->app);
        $apiRequest->setRouteResolver(function (): Route {
            $route = new Route('POST', 'api/media/{type}', []);
            $route->bind(ProcessMediaRequest::create('/api/media/video', 'POST'));

            return $route;
        });

        $this->assertSame($adminRequest->rules()['file'], $apiRequest->rules()['file']);
        $this->assertSame($adminRequest->messages()['file.max'], $apiRequest->messages()['file.max']);
        $this->assertSame($adminRequest->messages()['file.mimes'], $apiRequest->messages()['file.mimes']);
    }
}
