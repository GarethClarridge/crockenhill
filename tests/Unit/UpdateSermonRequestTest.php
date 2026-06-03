<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Requests\UpdateSermonRequest;
use App\Models\Sermon;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdateSermonRequestTest extends TestCase
{
    private UpdateSermonRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new UpdateSermonRequest;
    }

    #[Test]
    public function authorize_allows_user_with_update_permission()
    {
        // Mock a sermon object
        $sermon = \Mockery::mock(Sermon::class);

        // Mock a user object
        $user = \Mockery::mock(\stdClass::class);
        $user->shouldReceive('can')
            ->once()
            ->with('update', $sermon)
            ->andReturn(true);

        // Create a partial mock of the request that overrides the route and user methods
        $request = \Mockery::mock(UpdateSermonRequest::class)->makePartial();
        $request->shouldReceive('route')->with('sermon')->andReturn($sermon);
        $request->shouldReceive('user')->andReturn($user);

        $this->assertTrue($request->authorize());
    }

    #[Test]
    public function authorize_denies_user_without_update_permission()
    {
        // Mock a sermon object
        $sermon = \Mockery::mock(Sermon::class);

        // Mock a user object
        $user = \Mockery::mock(\stdClass::class);
        $user->shouldReceive('can')
            ->once()
            ->with('update', $sermon)
            ->andReturn(false);

        // Create a partial mock of the request that overrides the route and user methods
        $request = \Mockery::mock(UpdateSermonRequest::class)->makePartial();
        $request->shouldReceive('route')->with('sermon')->andReturn($sermon);
        $request->shouldReceive('user')->andReturn($user);

        $this->assertFalse($request->authorize());
    }

    #[Test]
    #[DataProvider('validationDataProvider')]
    public function validation_rules(array $data, bool $shouldPass, array $expectedErrors = [])
    {
        // For UpdateSermonRequest, route parameters might be needed for the request object
        // if rules depend on them (e.g., ignore unique rule for current model).
        // In this case, UpdateSermonRequest rules don't seem to depend on route params directly.
        // However, to be safe, an empty array for route parameters is passed.
        $this->request->setRouteResolver(function () {
            $route = $this->getMockBuilder(Route::class)
                ->disableOriginalConstructor()
                ->getMock();
            $route->method('parameters')->willReturn([]); // No route parameters needed for these rules

            return $route;
        });

        $validator = Validator::make($data, $this->request->rules(), $this->request->messages());

        $this->assertEquals(
            $shouldPass,
            $validator->passes(),
            $shouldPass ? 'Validation should have passed but failed.' : 'Validation should have failed but passed. Errors: '.print_r($validator->errors()->toArray(), true)
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
        $validPoints = [['point' => 'P1', 'sub_points' => ['S1.1']]];
        $invalidPoints = 'This is not an array';

        return [
            'all_valid_data_with_points' => [
                'data' => [
                    'title' => 'Valid Title',
                    'slug' => 'valid-title',
                    'date' => '2024-01-01',
                    'service' => 'morning',
                    'series' => 'Valid Series',
                    'reference' => 'John 1:1',
                    'preacher' => 'Valid Preacher',
                    'points' => $validPoints,
                ],
                'shouldPass' => true,
            ],
            'all_valid_data_null_points_series_ref' => [
                'data' => [
                    'title' => 'Valid Title',
                    'slug' => 'valid-title',
                    'date' => '2024-01-01',
                    'service' => 'evening',
                    'series' => null,
                    'reference' => null,
                    'preacher' => 'Valid Preacher',
                    'points' => null,
                ],
                'shouldPass' => true,
            ],

            // Title validation
            'title_missing' => [['date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['title' => 'required']],
            'title_too_long' => [['title' => str_repeat('a', 256), 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['title' => '255 characters']],

            // Date validation
            'date_missing' => [['title' => 'VT', 'service' => 'morning', 'preacher' => 'VP'], false, ['date' => 'required']],
            'date_invalid_format' => [['title' => 'VT', 'date' => '01/01/2024', 'service' => 'morning', 'preacher' => 'VP'], false, ['date' => 'Y-m-d']],

            // Service validation
            'service_missing' => [['title' => 'VT', 'date' => '2024-01-01', 'preacher' => 'VP'], false, ['service' => 'required']],
            'service_invalid_value' => [['title' => 'VT', 'date' => '2024-01-01', 'service' => 'special', 'preacher' => 'VP'], false, ['service' => 'selected service is invalid']],

            // Points validation
            'points_invalid_array' => [['title' => 'VT', 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP', 'points' => $invalidPoints], false, ['points' => 'must be an array']],
            'points_valid_empty_array' => [
                'data' => ['title' => 'VT', 'slug' => 'vt', 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP', 'points' => []],
                'shouldPass' => true,
            ],
            'points_empty_string_is_treated_as_null_and_passes' => [
                // Assuming ConvertEmptyStringsToNull middleware is active, '' becomes null, and nullable|array passes.
                'data' => ['title' => 'VT', 'slug' => 'vt', 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP', 'points' => ''],
                'shouldPass' => true,
                'expectedErrors' => [], // No errors expected if it passes
            ],

            // Slug validation
            'slug_missing' => [['title' => 'VT', 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['slug' => 'required']],
            'slug_invalid_format' => [['title' => 'VT', 'slug' => 'invalid slug', 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['slug' => 'format is invalid']],
            'slug_too_long' => [['title' => 'VT', 'slug' => str_repeat('a', 256), 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['slug' => '255 characters']],

            // Series validation (nullable, so only check max length if provided)
            'series_too_long' => [['title' => 'VT', 'date' => '2024-01-01', 'service' => 'morning', 'series' => str_repeat('b', 256), 'preacher' => 'VP'], false, ['series' => '255 characters']],

            // Preacher validation
            'preacher_missing' => [['title' => 'VT', 'date' => '2024-01-01', 'service' => 'morning'], false, ['preacher' => 'required']],
        ];
    }
}
