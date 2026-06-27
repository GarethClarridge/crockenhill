<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Http\Requests\ConfirmMediaSegmentRequest;
use App\Http\Requests\UpdateSermonRequest;
use App\Livewire\Forms\SermonFormData;
use App\Rules\SermonPointElement;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SermonValidationSecurityTest extends TestCase
{
    #[Test]
    public function update_sermon_request_rejects_oversized_flat_points(): void
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

        $this->assertFalse($validator->passes(), 'Validation should have failed for oversized flat sermon point.');
        $this->assertTrue($validator->errors()->has('points.0'));
    }

    #[Test]
    public function update_sermon_request_rejects_oversized_nested_points(): void
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
                ['point' => str_repeat('b', 256), 'sub_points' => []], // Main point too long
            ],
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->passes(), 'Validation should have failed for oversized nested main point.');
        $this->assertTrue($validator->errors()->has('points.0.point'));
    }

    #[Test]
    public function update_sermon_request_rejects_oversized_sub_points(): void
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
                ['point' => 'Valid point', 'sub_points' => [str_repeat('c', 256)]], // Sub-point too long
            ],
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->passes(), 'Validation should have failed for oversized sub-point.');
        $this->assertTrue($validator->errors()->has('points.0.sub_points.0'));
    }

    #[Test]
    public function update_sermon_request_rejects_non_string_scalar_flat_points(): void
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
                999999, // A bare integer must not launder past the string guard.
            ],
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->passes(), 'Validation should have failed for a non-string scalar flat point.');
        $this->assertTrue($validator->errors()->has('points.0'));
    }

    #[Test]
    public function update_sermon_request_accepts_valid_mixed_points(): void
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
                'Flat point',
                ['point' => 'Nested point', 'sub_points' => ['Sub 1', 'Sub 2']],
                null,
            ],
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->passes(), 'Validation should have passed for valid mixed sermon points. Errors: '.print_r($validator->errors()->toArray(), true));
    }

    #[Test]
    public function confirm_media_segment_request_rejects_overflow_id(): void
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
    public function sermon_form_data_rules_contain_points_limit(): void
    {
        // Mock the Livewire component and property name required by the Form constructor
        /** @var Component&MockInterface $component */
        $component = \Mockery::mock(Component::class);

        // We use an anonymous class to expose the protected rules() method without reflection
        $form = new class($component, 'form') extends SermonFormData
        {
            /** @return array<string, mixed> */
            public function getRules(): array
            {
                return $this->rules();
            }
        };

        $rules = $form->getRules();

        $this->assertArrayHasKey('points.*', $rules);
        $this->assertContains('nullable', $rules['points.*']);
        $this->assertNotEmpty(array_filter(
            $rules['points.*'],
            fn ($rule): bool => $rule instanceof SermonPointElement
        ), 'points.* should guard flat elements with the SermonPointElement rule.');

        $this->assertArrayHasKey('points.*.point', $rules);
        $this->assertContains('max:255', $rules['points.*.point']);

        $this->assertArrayHasKey('points.*.sub_points.*', $rules);
        $this->assertContains('max:255', $rules['points.*.sub_points.*']);
    }
}
