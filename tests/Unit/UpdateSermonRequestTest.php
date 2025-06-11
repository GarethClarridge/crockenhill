<?php

namespace Tests\Unit;

use Tests\TestCase;
use Crockenhill\Http\Requests\UpdateSermonRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;

class UpdateSermonRequestTest extends TestCase
{
    private UpdateSermonRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new UpdateSermonRequest();
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
        // For UpdateSermonRequest, route parameters might be needed for the request object
        // if rules depend on them (e.g., ignore unique rule for current model).
        // In this case, UpdateSermonRequest rules don't seem to depend on route params directly.
        // However, to be safe, an empty array for route parameters is passed.
        $this->request->setRouteResolver(function () {
            $route = $this->getMockBuilder(\Illuminate\Routing\Route::class)
                          ->disableOriginalConstructor()
                          ->getMock();
            $route->method('parameters')->willReturn([]); // No route parameters needed for these rules
            return $route;
        });

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
        $validJsonPoints = json_encode([['point' => 'P1', 'sub_points' => ['S1.1']]]);
        $invalidJsonPoints = 'This is not json';

        return [
            'all_valid_data_with_points' => [
                'data' => [
                    'title' => 'Valid Title',
                    'date' => '2024-01-01',
                    'service' => 'morning',
                    'series' => 'Valid Series',
                    'reference' => 'John 1:1',
                    'preacher' => 'Valid Preacher',
                    'points' => $validJsonPoints,
                ],
                'shouldPass' => true,
            ],
            'all_valid_data_null_points_series_ref' => [
                'data' => [
                    'title' => 'Valid Title',
                    'date' => '2024-01-01',
                    'service' => 'evening',
                    'series' => null,
                    'reference' => null,
                    'preacher' => 'Valid Preacher',
                    'points' => null,
                ],
                'shouldPass' => true
            ],

            // Title validation
            'title_missing' => [['date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['title' => 'required']],
            'title_too_long' => [['title' => str_repeat('a', 256), 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP'], false, ['title' => '255 characters']],

            // Date validation
            'date_missing' => [['title' => 'VT', 'service' => 'morning', 'preacher' => 'VP'], false, ['date' => 'required']],
            'date_invalid_format' => [['title' => 'VT', 'date' => '01/01/2024', 'service' => 'morning', 'preacher' => 'VP'], false, ['date' => 'Y-m-d']],

            // Service validation
            'service_missing' => [['title' => 'VT', 'date' => '2024-01-01', 'preacher' => 'VP'], false, ['service' => 'required']],
            'service_invalid_value' => [['title' => 'VT', 'date' => '2024-01-01', 'service' => 'special', 'preacher' => 'VP'], false, ['service' => 'valid service']],

            // Points validation
            'points_invalid_json' => [['title' => 'VT', 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP', 'points' => $invalidJsonPoints], false, ['points' => 'valid JSON structure']],
            'points_valid_empty_json_array' => [ // Empty array is valid JSON
                'data' => ['title' => 'VT', 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP', 'points' => '[]'],
                'shouldPass' => true,
            ],
            'points_valid_empty_string_for_nullable' => [ // Empty string should fail json, but nullable makes it pass if it becomes null
                                                          // The 'json' rule implies it must be a valid JSON string or null. An empty string is not valid JSON.
                'data' => ['title' => 'VT', 'date' => '2024-01-01', 'service' => 'morning', 'preacher' => 'VP', 'points' => ''],
                'shouldPass' => false, // Laravel's 'json' rule: empty string is not valid json.
                'expectedErrors' => ['points' => 'valid JSON structure']
            ],

            // Series validation (nullable, so only check max length if provided)
            'series_too_long' => [['title' => 'VT', 'date' => '2024-01-01', 'service' => 'morning', 'series' => str_repeat('b', 256), 'preacher' => 'VP'], false, ['series' => '255 characters']],

            // Preacher validation
            'preacher_missing' => [['title' => 'VT', 'date' => '2024-01-01', 'service' => 'morning'], false, ['preacher' => 'required']],
        ];
    }
}
