<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ChurchServiceReviewState;
use App\Enums\SermonService;
use App\Models\ChurchService;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceValidationTest extends TestCase
{
    private function filterDatabaseRules(array $rules): array
    {
        return array_filter($rules, function ($rule) {
            $ruleString = (string) $rule;

            return ! str_starts_with($ruleString, 'exists:') && ! str_starts_with($ruleString, 'unique:');
        });
    }

    #[Test]
    public function it_validates_required_fields(): void
    {
        $rules = array_map(fn ($r) => $this->filterDatabaseRules($r), ChurchService::validationRules());
        $validator = Validator::make([], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('date', $validator->errors()->toArray());
        $this->assertArrayHasKey('service', $validator->errors()->toArray());
        $this->assertArrayHasKey('source', $validator->errors()->toArray());
        $this->assertArrayHasKey('review_state', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_enum_values(): void
    {
        $rules = array_map(fn ($r) => $this->filterDatabaseRules($r), ChurchService::validationRules());

        $data = [
            'date' => '2023-01-01',
            'service' => 'invalid-service',
            'source' => 'test-source',
            'review_state' => 'invalid-state',
            'review_reason' => str_repeat('a', 256),
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('service', $validator->errors()->toArray());
        $this->assertArrayHasKey('review_state', $validator->errors()->toArray());
        $this->assertArrayHasKey('review_reason', $validator->errors()->toArray());
    }

    #[Test]
    public function it_passes_with_valid_data(): void
    {
        $rules = array_map(fn ($r) => $this->filterDatabaseRules($r), ChurchService::validationRules());

        $data = [
            'date' => '2023-01-01',
            'service' => SermonService::Morning->value,
            'source' => 'test-source',
            'needs_review' => true,
            'review_state' => ChurchServiceReviewState::Reviewed->value,
            'review_reason' => 'Incoming service data conflicted with existing items.',
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->fails(), $validator->errors()->first());
    }

    #[Test]
    public function it_validates_boolean_fields(): void
    {
        $rules = array_map(fn ($r) => $this->filterDatabaseRules($r), ChurchService::validationRules());

        $data = [
            'date' => '2023-01-01',
            'service' => SermonService::Morning->value,
            'source' => 'test-source',
            'needs_review' => 'not-a-boolean',
            'review_state' => ChurchServiceReviewState::NotReviewed->value,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('needs_review', $validator->errors()->toArray());
    }

    #[Test]
    public function it_allows_a_null_review_reason(): void
    {
        $rules = array_map(fn ($r) => $this->filterDatabaseRules($r), ChurchService::validationRules());

        $data = [
            'date' => '2023-01-01',
            'service' => SermonService::Morning->value,
            'source' => 'test-source',
            'review_state' => ChurchServiceReviewState::NotReviewed->value,
            'review_reason' => null,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->fails(), $validator->errors()->first());
    }
}
