<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests;

use App\Enums\MeetingType;
use App\Http\Requests\UpdateMeetingRequest;
use App\Models\Meeting;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateMeetingRequestTest extends TestCase
{
    use DatabaseTransactions;

    private UpdateMeetingRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new UpdateMeetingRequest;
    }

    #[Test]
    public function authorize_allows_admin_with_permission(): void
    {
        $meeting = Meeting::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        $request = UpdateMeetingRequest::create("/admin/meetings/{$meeting->slug}", 'PUT');
        $request->setContainer($this->app);
        $request->setUserResolver(fn () => $admin);
        $request->setRouteResolver(function () use ($meeting) {
            $route = app('router')->getRoutes()->getByName('meetings.update');
            $route->bind(app('request'));
            $route->setParameter('meeting', $meeting);

            return $route;
        });

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function authorize_denies_guests(): void
    {
        $meeting = Meeting::factory()->create();

        $request = UpdateMeetingRequest::create("/admin/meetings/{$meeting->slug}", 'PUT');
        $request->setContainer($this->app);
        $request->setUserResolver(fn () => null);
        $request->setRouteResolver(function () use ($meeting) {
            $route = app('router')->getRoutes()->getByName('meetings.update');
            $route->bind(app('request'));
            $route->setParameter('meeting', $meeting);

            return $route;
        });

        $this->assertFalse($request->authorize());
    }

    #[Test]
    public function authorize_denies_user_without_permission(): void
    {
        $meeting = Meeting::factory()->create();
        $user = User::factory()->create(['is_admin' => false]);

        $request = UpdateMeetingRequest::create("/admin/meetings/{$meeting->slug}", 'PUT');
        $request->setContainer($this->app);
        $request->setUserResolver(fn () => $user);
        $request->setRouteResolver(function () use ($meeting) {
            $route = app('router')->getRoutes()->getByName('meetings.update');
            $route->bind(app('request'));
            $route->setParameter('meeting', $meeting);

            return $route;
        });

        $this->assertFalse($request->authorize());
    }

    #[Test]
    #[DataProvider('validationDataProvider')]
    public function validation_rules(array $data, bool $shouldPass, array $expectedErrors = []): void
    {
        // Setup route for unique/exists rules if needed
        $meeting = null;
        if (isset($data['_existing_meeting'])) {
            $meeting = $data['_existing_meeting'];
            unset($data['_existing_meeting']);
        }

        $this->request->setRouteResolver(function () use ($meeting) {
            $route = $this->getMockBuilder(\Illuminate\Routing\Route::class)
                ->disableOriginalConstructor()
                ->getMock();
            $route->method('parameter')->with('meeting')->willReturn($meeting);
            $route->method('parameters')->willReturn($meeting ? ['meeting' => $meeting] : []);

            return $route;
        });

        $validator = Validator::make($data, $this->request->rules());

        $this->assertEquals(
            $shouldPass,
            $validator->passes(),
            $shouldPass ? 'Validation should have passed but failed. Errors: '.print_r($validator->errors()->toArray(), true) : 'Validation should have failed but passed.'
        );

        if (! $shouldPass && ! empty($expectedErrors)) {
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
        return [
            'all_valid_data' => [
                'data' => [
                    'slug' => 'valid-slug',
                    'type' => MeetingType::Adults->value,
                    'day' => 'Monday',
                    'who' => 'Everyone',
                    'pictures' => true,
                    'start_time' => '10:00',
                    'end_time' => '11:00',
                ],
                'shouldPass' => true,
            ],
            'slug_required' => [
                'data' => [
                    'type' => MeetingType::Adults->value,
                    'day' => 'Monday',
                    'who' => 'Everyone',
                    'pictures' => true,
                ],
                'shouldPass' => false,
                'expectedErrors' => ['slug' => 'required'],
            ],
            'slug_invalid_format' => [
                'data' => [
                    'slug' => 'Invalid Slug',
                    'type' => MeetingType::Adults->value,
                    'day' => 'Monday',
                    'who' => 'Everyone',
                    'pictures' => true,
                ],
                'shouldPass' => false,
                'expectedErrors' => ['slug' => 'format is invalid'],
            ],
            'type_required' => [
                'data' => [
                    'slug' => 'valid-slug',
                    'day' => 'Monday',
                    'who' => 'Everyone',
                    'pictures' => true,
                ],
                'shouldPass' => false,
                'expectedErrors' => ['type' => 'required'],
            ],
            'type_invalid_enum' => [
                'data' => [
                    'slug' => 'valid-slug',
                    'type' => 'invalid-type',
                    'day' => 'Monday',
                    'who' => 'Everyone',
                    'pictures' => true,
                ],
                'shouldPass' => false,
                'expectedErrors' => ['type' => 'invalid'],
            ],
            'frequency_required_if_recurring' => [
                'data' => [
                    'slug' => 'valid-slug',
                    'type' => MeetingType::Adults->value,
                    'day' => 'Monday',
                    'who' => 'Everyone',
                    'pictures' => true,
                    'is_recurring' => true,
                    // frequency missing
                ],
                'shouldPass' => false,
                'expectedErrors' => ['frequency' => 'required when is recurring is true'],
            ],
            'frequency_invalid_enum' => [
                'data' => [
                    'slug' => 'valid-slug',
                    'type' => MeetingType::Adults->value,
                    'day' => 'Monday',
                    'who' => 'Everyone',
                    'pictures' => true,
                    'is_recurring' => true,
                    'frequency' => 'invalid-frequency',
                ],
                'shouldPass' => false,
                'expectedErrors' => ['frequency' => 'invalid'],
            ],
            'end_time_must_be_after_or_equal_start_time' => [
                'data' => [
                    'slug' => 'valid-slug',
                    'type' => MeetingType::Adults->value,
                    'day' => 'Monday',
                    'who' => 'Everyone',
                    'pictures' => true,
                    'start_time' => '11:00',
                    'end_time' => '10:00',
                ],
                'shouldPass' => false,
                'expectedErrors' => ['end_time' => 'after or equal to start time'],
            ],
            'start_time_invalid_format' => [
                'data' => [
                    'slug' => 'valid-slug',
                    'type' => MeetingType::Adults->value,
                    'day' => 'Monday',
                    'who' => 'Everyone',
                    'pictures' => true,
                    'start_time' => '10:00:000',
                ],
                'shouldPass' => false,
                'expectedErrors' => ['start_time' => 'match the format'],
            ],
        ];
    }

    #[Test]
    public function slug_must_be_unique(): void
    {
        $existingMeeting = Meeting::factory()->create(['slug' => 'existing-slug']);

        $data = [
            'slug' => 'existing-slug',
            'type' => MeetingType::Adults->value,
            'day' => 'Monday',
            'who' => 'Everyone',
            'pictures' => true,
        ];

        $validator = Validator::make($data, $this->request->rules());
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('slug'));
    }

    #[Test]
    public function slug_can_be_same_as_current_meeting(): void
    {
        $meeting = Meeting::factory()->create(['slug' => 'current-slug']);

        $data = [
            'slug' => 'current-slug',
            'type' => MeetingType::Adults->value,
            'day' => 'Monday',
            'who' => 'Everyone',
            'pictures' => true,
        ];

        $this->request->setRouteResolver(function () use ($meeting) {
            $route = $this->getMockBuilder(\Illuminate\Routing\Route::class)
                ->disableOriginalConstructor()
                ->getMock();
            $route->method('parameter')->with('meeting')->willReturn($meeting);

            return $route;
        });

        $validator = Validator::make($data, $this->request->rules());
        $this->assertTrue($validator->passes(), 'Validation failed: '.print_r($validator->errors()->toArray(), true));
    }

    #[Test]
    public function page_id_must_exist(): void
    {
        $data = [
            'slug' => 'valid-slug',
            'type' => MeetingType::Adults->value,
            'day' => 'Monday',
            'who' => 'Everyone',
            'pictures' => true,
            'page_id' => 99999,
        ];

        $validator = Validator::make($data, $this->request->rules());
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('page_id'));
    }

    #[Test]
    public function page_id_must_be_unique_except_current(): void
    {
        $page = Page::factory()->create();
        $otherMeeting = Meeting::factory()->create(['page_id' => $page->id]);
        $currentMeeting = Meeting::factory()->create(['page_id' => null]);

        $data = [
            'slug' => 'valid-slug',
            'type' => MeetingType::Adults->value,
            'day' => 'Monday',
            'who' => 'Everyone',
            'pictures' => true,
            'page_id' => $page->id,
        ];

        // Fail unique with other meeting's page_id
        $validator = Validator::make($data, $this->request->rules());
        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('page_id'));

        // Pass unique with current meeting's page_id
        // We need to swap them to make the current one own the page_id
        $otherMeeting->update(['page_id' => null]);
        $currentMeeting->update(['page_id' => $page->id]);

        $this->request->setRouteResolver(function () use ($currentMeeting) {
            $route = $this->getMockBuilder(\Illuminate\Routing\Route::class)
                ->disableOriginalConstructor()
                ->getMock();
            $route->method('parameter')->with('meeting')->willReturn($currentMeeting);

            return $route;
        });

        $validator = Validator::make($data, $this->request->rules());
        $this->assertTrue($validator->passes(), 'Validation failed: '.print_r($validator->errors()->toArray(), true));
    }
}
