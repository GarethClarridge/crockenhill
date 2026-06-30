<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ChurchServiceCanonicalConflictReason;
use App\Enums\ChurchServiceCanonicalConflictState;
use App\Enums\ChurchServiceReviewState;
use App\Enums\SermonService;
use App\Models\ChurchService;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChurchServiceValidationTest extends TestCase
{
    #[Test]
    public function it_validates_required_fields(): void
    {
        $rules = ChurchService::validationRules();
        $validator = Validator::make([], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('date', $validator->errors()->toArray());
        $this->assertArrayHasKey('service', $validator->errors()->toArray());
        $this->assertArrayHasKey('source', $validator->errors()->toArray());
        $this->assertArrayHasKey('review_state', $validator->errors()->toArray());
        $this->assertArrayHasKey('canonical_conflict_state', $validator->errors()->toArray());
    }

    #[Test]
    public function it_validates_enum_values(): void
    {
        $rules = ChurchService::validationRules();

        $data = [
            'date' => '2023-01-01',
            'service' => 'invalid-service',
            'source' => 'test-source',
            'review_state' => 'invalid-state',
            'canonical_conflict_state' => 'invalid-state',
            'canonical_conflict_reason' => 'invalid-reason',
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('service', $validator->errors()->toArray());
        $this->assertArrayHasKey('review_state', $validator->errors()->toArray());
        $this->assertArrayHasKey('canonical_conflict_state', $validator->errors()->toArray());
        $this->assertArrayHasKey('canonical_conflict_reason', $validator->errors()->toArray());
    }

    #[Test]
    public function it_passes_with_valid_data(): void
    {
        $rules = ChurchService::validationRules();

        $data = [
            'date' => '2023-01-01',
            'service' => SermonService::Morning->value,
            'source' => 'test-source',
            'needs_review' => true,
            'review_state' => ChurchServiceReviewState::Reviewed->value,
            'canonical_conflict_state' => ChurchServiceCanonicalConflictState::None->value,
            'canonical_conflict_reason' => null,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->fails(), $validator->errors()->first());
    }

    #[Test]
    public function it_validates_boolean_fields(): void
    {
        $rules = ChurchService::validationRules();

        $data = [
            'date' => '2023-01-01',
            'service' => SermonService::Morning->value,
            'source' => 'test-source',
            'needs_review' => 'not-a-boolean',
            'review_state' => ChurchServiceReviewState::NotReviewed->value,
            'canonical_conflict_state' => ChurchServiceCanonicalConflictState::None->value,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('needs_review', $validator->errors()->toArray());
    }

    #[Test]
    public function it_allows_valid_canonical_conflict_reason(): void
    {
        $rules = ChurchService::validationRules();

        $data = [
            'date' => '2023-01-01',
            'service' => SermonService::Morning->value,
            'source' => 'test-source',
            'review_state' => ChurchServiceReviewState::NotReviewed->value,
            'canonical_conflict_state' => ChurchServiceCanonicalConflictState::Detected->value,
            'canonical_conflict_reason' => ChurchServiceCanonicalConflictReason::ConflictsOnly->value,
        ];

        $validator = Validator::make($data, $rules);

        $this->assertFalse($validator->fails(), $validator->errors()->first());
    }
}
