<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\ProcessMediaRequest;
use App\Http\Requests\UpdateMeetingRequest;
use App\Models\Meeting;
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

    #[Test]
    public function update_meeting_request_denies_guests(): void
    {
        $meeting = \Mockery::mock(Meeting::class);
        $request = \Mockery::mock(UpdateMeetingRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn(null);
        $request->shouldReceive('route')->with('meeting')->andReturn($meeting);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function update_meeting_request_denies_missing_route_model(): void
    {
        $user = \Mockery::mock(\stdClass::class);
        $user->shouldNotReceive('can');

        $request = \Mockery::mock(UpdateMeetingRequest::class)->makePartial();
        $request->shouldReceive('user')->andReturn($user);
        $request->shouldReceive('route')->with('meeting')->andReturn(null);

        $this->assertFalse($request->authorize());
    }
}
