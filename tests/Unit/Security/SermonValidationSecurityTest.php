<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Http\Requests\ConfirmMediaSegmentRequest;
use App\Http\Requests\UpdateSermonRequest;
use App\Livewire\Forms\SermonFormData;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonValidationSecurityTest extends TestCase
{
    #[Test]
    public function update_sermon_request_rejects_oversized_points()
    {
        $request = new UpdateSermonRequest;
        $rules = $request->rules();

        // Remove DB dependent rules for unit test
        unset($rules['slug'], $rules['preacher_id'], $rules['scripture_passage_id'], $rules['livestream_processing_id']);

        $data = [
            'title' => 'Valid Title',
            'date' => '2024-01-01',
            'service' => 'morning',
            'preacher' => 'Valid Preacher',
            'points' => [
                str_repeat('a', 256), // Exceeds 255
            ],
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->passes(), 'Validation should have failed for oversized sermon point.');
        $this->assertTrue($validator->errors()->has('points.0'));
    }

    #[Test]
    public function update_sermon_request_accepts_valid_points()
    {
        $request = new UpdateSermonRequest;
        $rules = $request->rules();

        // Remove DB dependent rules for unit test
        unset($rules['slug'], $rules['preacher_id'], $rules['scripture_passage_id'], $rules['livestream_processing_id']);

        $data = [
            'title' => 'Valid Title',
            'date' => '2024-01-01',
            'service' => 'morning',
            'preacher' => 'Valid Preacher',
            'points' => [
                str_repeat('a', 255), // Exactly 255
                'Valid point',
            ],
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->passes(), 'Validation should have passed for valid sermon points. Errors: '.print_r($validator->errors()->toArray(), true));
    }

    #[Test]
    public function update_sermon_request_accepts_null_points_elements()
    {
        $request = new UpdateSermonRequest;
        $rules = $request->rules();

        // Remove DB dependent rules for unit test
        unset($rules['slug'], $rules['preacher_id'], $rules['scripture_passage_id'], $rules['livestream_processing_id']);

        $data = [
            'title' => 'Valid Title',
            'date' => '2024-01-01',
            'service' => 'morning',
            'preacher' => 'Valid Preacher',
            'points' => [
                'Point 1',
                null,
            ],
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->passes(), 'Validation should have passed for null sermon points. Errors: '.print_r($validator->errors()->toArray(), true));
    }

    #[Test]
    public function confirm_media_segment_request_rejects_overflow_id()
    {
        $request = new ConfirmMediaSegmentRequest;
        $rules = $request->rules();

        // Remove DB dependent rules
        unset($rules['segment_id'][array_search('exists:livestream_segments,id', $rules['segment_id'])]);

        $data = ['segment_id' => 2147483648]; // Max + 1

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->passes(), 'Validation should have failed for overflow segment_id.');
        $this->assertTrue($validator->errors()->has('segment_id'));
    }

    #[Test]
    public function sermon_form_data_rules_contain_points_limit()
    {
        // Mock the Livewire component and property name required by the Form constructor
        $component = \Mockery::mock(Component::class);
        $form = new SermonFormData($component, 'form');

        // We use reflection to access the protected rules() method
        $reflection = new \ReflectionClass($form);
        $method = $reflection->getMethod('rules');
        $method->setAccessible(true);

        $rules = $method->invoke($form);

        $this->assertArrayHasKey('points.*', $rules);
        $this->assertContains('max:255', $rules['points.*']);
        $this->assertContains('nullable', $rules['points.*']);
    }
}
