<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\UploadChurchServiceRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class UploadChurchServiceRequestTest extends TestCase
{
    #[Test]
    public function test_file_is_required(): void
    {
        $request = new UploadChurchServiceRequest;

        $validator = Validator::make([], $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('file'));
    }

    #[Test]
    public function test_file_must_be_valid_type(): void
    {
        $request = new UploadChurchServiceRequest;

        $validator = Validator::make([
            'file' => UploadedFile::fake()->create('service.txt', 10, 'text/plain'),
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('file'));
    }

    #[Test]
    public function test_file_max_size_enforced(): void
    {
        config()->set('service-tracking.upload.max_size_kb', 0);

        $request = new UploadChurchServiceRequest;

        $validator = Validator::make([
            'file' => OpenLpArchiveFactory::makeUpload(),
        ], $request->rules(), $request->messages());

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('file'));
    }
}
