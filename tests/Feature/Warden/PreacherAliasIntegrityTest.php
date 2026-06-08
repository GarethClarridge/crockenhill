<?php

declare(strict_types=1);

namespace Tests\Feature\Warden;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherAliasIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_rejects_out_of_bounds_preacher_id(): void
    {
        $rules = PreacherAlias::validationRules();

        // Test below min
        $validator = Validator::make([
            'preacher_id' => 0,
            'alias' => 'Test Alias',
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('preacher_id', $validator->errors()->toArray());

        // Test above max
        $validator = Validator::make([
            'preacher_id' => 2147483648,
            'alias' => 'Test Alias',
        ], $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('preacher_id', $validator->errors()->toArray());
    }

    #[Test]
    public function it_accepts_valid_preacher_id(): void
    {
        $preacher = Preacher::factory()->create();
        $rules = PreacherAlias::validationRules();

        $validator = Validator::make([
            'preacher_id' => $preacher->id,
            'alias' => 'Valid Alias',
        ], $rules);

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function it_normalizes_alias_on_save(): void
    {
        $preacher = Preacher::factory()->create();

        $alias = PreacherAlias::create([
            'preacher_id' => $preacher->id,
            'alias' => '  Mark Drury  ',
        ]);

        $this->assertEquals('mark drury', $alias->alias);
    }
}
