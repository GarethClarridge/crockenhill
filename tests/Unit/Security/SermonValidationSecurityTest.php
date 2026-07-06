<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Http\Requests\ConfirmMediaSegmentRequest;
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
