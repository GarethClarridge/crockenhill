<?php

namespace Tests\Unit;

use Crockenhill\Http\Requests\UpdatePageRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Arr;
use Illuminate\Support\Str; // <--- ADD THIS LINE
use Tests\TestCase;

class UpdatePageRequestTest extends TestCase
{
    private UpdatePageRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new UpdatePageRequest();
    }

    /** @test */
    public function authorize_method_logic()
    {
        // Test when Gate allows
        Gate::shouldReceive('allows')->with('edit-pages')->once()->andReturn(true);
        $this->assertTrue($this->request->authorize(), 'Authorize should return true when Gate allows');

        // Test when Gate denies
        // Create a new request object to ensure it resolves the Gate facade again
        // with the new mock expectation.
        $requestDeny = new UpdatePageRequest();
        Gate::shouldReceive('allows')->with('edit-pages')->once()->andReturn(false);
        $this->assertFalse($requestDeny->authorize(), 'Authorize should return false when Gate denies');
    }

    /**
     * @test
     * @dataProvider validationDataProvider
     */
    public function it_validates_page_update_data(array $data, bool $shouldPass, array $expectedInvalidFields = [])
    {
        $validator = Validator::make($data, $this->request->rules());

        $this->assertEquals($shouldPass, $validator->passes());

        if (!$shouldPass) {
            $this->assertEqualsCanonicalizing($expectedInvalidFields, array_keys($validator->errors()->toArray()));
        }
    }

    public static function validationDataProvider(): array
    {
        // Rules are identical to StorePageRequest
        $validData = [
            'heading' => 'Updated Test Heading',
            'area' => 'church', // Valid ENUM value
            'markdown' => 'Some updated valid markdown.',
            'navigation-radio' => 'no',
            'description' => 'Updated optional description',
            // 'heading-image' is nullable, so not required
        ];

        return [
            'valid_data' => [$validData, true],
            'missing_heading' => [array_merge($validData, ['heading' => '']), false, ['heading']],
            'null_heading' => [Arr::except($validData, ['heading']), false, ['heading']],
            'max_heading' => [array_merge($validData, ['heading' => Str::random(256)]), false, ['heading']],
            'valid_max_heading' => [array_merge($validData, ['heading' => Str::random(255)]), true],

            'missing_area' => [array_merge($validData, ['area' => '']), false, ['area']],
            'null_area' => [Arr::except($validData, ['area']), false, ['area']],
            'invalid_area_value' => [array_merge($validData, ['area' => 'invalid-area']), false, ['area']],
            'valid_area_christ' => [array_merge($validData, ['area' => 'christ']), true],
            'valid_area_community' => [array_merge($validData, ['area' => 'community']), true],

            'missing_markdown' => [array_merge($validData, ['markdown' => '']), false, ['markdown']],
            'null_markdown' => [Arr::except($validData, ['markdown']), false, ['markdown']],

            'missing_navigation_radio' => [array_merge($validData, ['navigation-radio' => '']), false, ['navigation-radio']],
            'null_navigation_radio' => [Arr::except($validData, ['navigation-radio']), false, ['navigation-radio']],
            'invalid_navigation_radio_value' => [array_merge($validData, ['navigation-radio' => 'maybe']), false, ['navigation-radio']],
            'valid_navigation_radio_yes' => [array_merge($validData, ['navigation-radio' => 'yes']), true],

            'valid_data_no_description' => [ Arr::except($validData, ['description']), true],
            'valid_data_null_description' => [array_merge($validData, ['description' => null]), true],

            // heading-image is nullable, these tests ensure providing nothing or null is valid
            'valid_data_without_image_key' => [$validData, true],
            'valid_data_with_null_image' => [array_merge($validData, ['heading-image' => null]), true],
            // Actual image validation (type, size) is harder to unit test here and usually done in feature tests.
        ];
    }
}
