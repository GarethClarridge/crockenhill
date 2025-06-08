<?php

namespace Tests\Unit;

use Crockenhill\Http\Requests\StorePageRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Arr; // Added for data provider
use Tests\TestCase;

class StorePageRequestTest extends TestCase
{
    private StorePageRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = new StorePageRequest();
    }

    /** @test */
    public function authorize_method_logic()
    {
        // Test when Gate allows
        Gate::shouldReceive('allows')->with('edit-pages')->once()->andReturn(true);
        $this->assertTrue($this->request->authorize(), 'Authorize should return true when Gate allows');

        // Test when Gate denies
        // Re-establish the mock for the 'denies' case.
        // Create a new request object to ensure it resolves the Gate facade again
        // with the new mock expectation.
        Gate::shouldReceive('allows')->with('edit-pages')->once()->andReturn(false);
        $requestDeny = new StorePageRequest();
        $this->assertFalse($requestDeny->authorize(), 'Authorize should return false when Gate denies');
    }

    /**
     * @test
     * @dataProvider validationDataProvider
     */
    public function it_validates_page_data(array $data, bool $shouldPass, array $expectedInvalidFields = [])
    {
        $validator = Validator::make($data, $this->request->rules());

        $this->assertEquals($shouldPass, $validator->passes());

        if (!$shouldPass) {
            $this->assertEqualsCanonicalizing($expectedInvalidFields, array_keys($validator->errors()->toArray()));
        }
    }

    public static function validationDataProvider(): array
    {
        $validData = [
            'heading' => 'Test Heading',
            'area' => 'church', // Changed to a valid ENUM value based on rules
            'markdown' => 'Some valid markdown.',
            'navigation-radio' => 'yes',
            'description' => 'Optional description',
        ];

        return [
            'valid_data' => [$validData, true],
            'missing_heading' => [array_merge($validData, ['heading' => '']), false, ['heading']],
            'null_heading' => [Arr::except($validData, ['heading']), false, ['heading']],
            'missing_area' => [array_merge($validData, ['area' => '']), false, ['area']],
            'null_area' => [Arr::except($validData, ['area']), false, ['area']],
            'invalid_area_value' => [array_merge($validData, ['area' => 'Test Area']), false, ['area']], // Added test for invalid area
            'missing_markdown' => [array_merge($validData, ['markdown' => '']), false, ['markdown']],
            'null_markdown' => [Arr::except($validData, ['markdown']), false, ['markdown']],
            'missing_navigation_radio' => [array_merge($validData, ['navigation-radio' => '']), false, ['navigation-radio']],
            'null_navigation_radio' => [Arr::except($validData, ['navigation-radio']), false, ['navigation-radio']],
            'invalid_navigation_radio_value' => [array_merge($validData, ['navigation-radio' => 'maybe']), false, ['navigation-radio']],
            'valid_data_no_description' => [ Arr::except($validData, ['description']), true],
            'valid_data_navigation_no' => [array_merge($validData, ['navigation-radio' => 'no']), true],
            // Added tests for heading-image (nullable, so not providing it should be valid)
            'valid_data_with_null_image' => [array_merge($validData, ['heading-image' => null]), true],
            'valid_data_without_image_key' => [$validData, true], // Already covered by valid_data
        ];
    }
}
