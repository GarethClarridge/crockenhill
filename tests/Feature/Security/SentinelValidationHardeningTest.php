<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Sermon;
use App\Http\Requests\ConfirmMediaSegmentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SentinelValidationHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that sermon points individual element length is bounded.
     */
    public function test_sermon_points_elements_are_bounded_by_length(): void
    {
        $rules = Sermon::validationRules();

        // Valid points pass
        $validData = ['points' => ['Point 1', 'Point 2']];
        $validator = Validator::make($validData, ['points.*' => $rules['points.*']]);
        $this->assertFalse($validator->fails());

        // Oversized point fails
        $invalidData = ['points' => [str_repeat('a', 256)]];
        $validator = Validator::make($invalidData, ['points.*' => $rules['points.*']]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('points.0', $validator->errors()->toArray());
    }

    /**
     * Test that segment_id in ConfirmMediaSegmentRequest is bounded.
     */
    public function test_confirm_media_segment_id_is_bounded(): void
    {
        $request = new ConfirmMediaSegmentRequest();
        $rules = $request->rules();

        $this->assertArrayHasKey('segment_id', $rules);
        $this->assertContains('max:2147483647', $rules['segment_id']);

        // Functional test: extremely large ID fails
        $invalidData = ['segment_id' => 2147483648];
        $validator = Validator::make($invalidData, ['segment_id' => $rules['segment_id']]);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('segment_id', $validator->errors()->toArray());

        // Functional test: max ID passes (if it exists, but here we just test validation rules)
        $validData = ['segment_id' => 2147483647];
        // We temporarily remove 'exists' rule for pure functional test of the 'max' rule
        $rulesWithoutExists = array_filter($rules['segment_id'], function($rule) {
            if (is_string($rule) && str_starts_with($rule, 'exists')) {
                return false;
            }
            if (is_object($rule) && get_class($rule) === 'Illuminate\Validation\Rules\Exists') {
                return false;
            }
            return true;
        });
        $validator = Validator::make($validData, ['segment_id' => $rulesWithoutExists]);
        $this->assertFalse($validator->fails(), print_r($validator->errors()->all(), true));
    }
}
