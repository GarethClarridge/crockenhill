<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\ApiTokenAbility;
use App\Http\Requests\CancelMediaProcessingRequest;
use App\Http\Requests\ConfirmMediaSegmentRequest;
use App\Http\Requests\MediaStatusRequest;
use App\Http\Requests\ProcessMediaRequest;
use App\Http\Requests\RetryMediaProcessingRequest;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RequestAuthorizationTest extends TestCase
{
    #[Test]
    public function process_media_request_denies_guests(): void
    {
        $request = \Mockery::mock(ProcessMediaRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn(null);

        $this->assertFalse($request->authorize());
    }

    // --- MediaStatusRequest ---

    #[Test]
    public function media_status_request_denies_guests(): void
    {
        $request = \Mockery::mock(MediaStatusRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn(null);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function media_status_request_denies_non_admin_users(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $request = \Mockery::mock(MediaStatusRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('bearerToken')->andReturn(null);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function media_status_request_denies_token_without_media_process_ability(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $request = \Mockery::mock(MediaStatusRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('bearerToken')->andReturn('test-token');
        $request->shouldReceive('user->tokenCan')
            ->with(ApiTokenAbility::MEDIA_PROCESS->value)
            ->andReturn(false);

        $this->assertFalse($request->authorize());
    }

    // --- CancelMediaProcessingRequest ---

    #[Test]
    public function cancel_media_request_denies_guests(): void
    {
        $request = \Mockery::mock(CancelMediaProcessingRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn(null);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function cancel_media_request_denies_non_admin_users(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $request = \Mockery::mock(CancelMediaProcessingRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('bearerToken')->andReturn(null);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function cancel_media_request_denies_token_without_media_process_ability(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $request = \Mockery::mock(CancelMediaProcessingRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('bearerToken')->andReturn('test-token');
        $request->shouldReceive('user->tokenCan')
            ->with(ApiTokenAbility::MEDIA_PROCESS->value)
            ->andReturn(false);

        $this->assertFalse($request->authorize());
    }

    // --- RetryMediaProcessingRequest ---

    #[Test]
    public function retry_media_request_denies_guests(): void
    {
        $request = \Mockery::mock(RetryMediaProcessingRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn(null);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function retry_media_request_denies_non_admin_users(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $request = \Mockery::mock(RetryMediaProcessingRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('bearerToken')->andReturn(null);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function retry_media_request_denies_token_without_media_process_ability(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $request = \Mockery::mock(RetryMediaProcessingRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('bearerToken')->andReturn('test-token');
        $request->shouldReceive('user->tokenCan')
            ->with(ApiTokenAbility::MEDIA_PROCESS->value)
            ->andReturn(false);

        $this->assertFalse($request->authorize());
    }

    // --- ConfirmMediaSegmentRequest ---

    #[Test]
    public function confirm_segment_request_denies_guests(): void
    {
        $request = \Mockery::mock(ConfirmMediaSegmentRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn(null);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function confirm_segment_request_denies_non_admin_users(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $request = \Mockery::mock(ConfirmMediaSegmentRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('bearerToken')->andReturn(null);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function confirm_segment_request_denies_token_without_media_process_ability(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        $request = \Mockery::mock(ConfirmMediaSegmentRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('bearerToken')->andReturn('test-token');
        $request->shouldReceive('user->tokenCan')
            ->with(ApiTokenAbility::MEDIA_PROCESS->value)
            ->andReturn(false);

        $this->assertFalse($request->authorize());
    }
}
