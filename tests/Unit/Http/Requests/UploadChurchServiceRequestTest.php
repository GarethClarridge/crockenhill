<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\ApiTokenAbility;
use App\Http\Requests\UploadChurchServiceRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\OpenLpArchiveFactory;
use Tests\TestCase;

class UploadChurchServiceRequestTest extends TestCase
{
    private function makeRequest(?User $user = null, ?string $bearerToken = null): UploadChurchServiceRequest
    {
        $request = UploadChurchServiceRequest::create('/', 'POST');

        if ($bearerToken !== null) {
            $request->headers->set('Authorization', 'Bearer '.$bearerToken);
        }

        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    }

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

    #[Test]
    public function test_authorize_denies_unverified_admins(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $request = $this->makeRequest($user);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function test_authorize_denies_pat_without_service_upload_ability(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('missing-ability', [ApiTokenAbility::MEDIA_PROCESS->value]);
        $requestUser = $user->withAccessToken($token->accessToken);
        $request = $this->makeRequest($requestUser, $token->plainTextToken);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function test_authorize_allows_verified_admin_with_service_upload_ability(): void
    {
        $user = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('service-upload', [ApiTokenAbility::SERVICE_UPLOAD->value]);
        $requestUser = $user->withAccessToken($token->accessToken);
        $request = $this->makeRequest($requestUser, $token->plainTextToken);

        $this->assertTrue($request->authorize());
    }
}
