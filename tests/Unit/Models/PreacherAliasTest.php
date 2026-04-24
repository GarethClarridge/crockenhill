<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Preacher;
use App\Models\PreacherAlias;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherAliasTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_has_fillable_attributes(): void
    {
        $preacher = Preacher::factory()->create();
        $data = [
            'preacher_id' => $preacher->id,
            'alias' => 'John Dory',
        ];

        $alias = new PreacherAlias($data);

        $this->assertEquals($data['preacher_id'], $alias->preacher_id);
        // Normalized to lowercase by model mutator
        $this->assertEquals('john dory', $alias->alias);
    }

    #[Test]
    public function it_belongs_to_a_preacher(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Mark Drury Alias Test',
            'slug' => 'mark-drury-alias-test',
        ]);
        $alias = PreacherAlias::create([
            'preacher_id' => $preacher->id,
            'alias' => 'Mark D',
        ]);

        $this->assertInstanceOf(Preacher::class, $alias->preacher);
        $this->assertEquals($preacher->id, $alias->preacher->id);
        $this->assertEquals('Mark Drury Alias Test', $alias->preacher->name);
    }

    #[Test]
    public function it_cannot_be_created_without_preacher_id(): void
    {
        $this->expectException(QueryException::class);

        PreacherAlias::create([
            'preacher_id' => null,
            'alias' => 'Some Alias',
        ]);
    }

    #[Test]
    public function it_can_be_created_and_retrieved(): void
    {
        $preacher = Preacher::factory()->create();
        PreacherAlias::create([
            'preacher_id' => $preacher->id,
            'alias' => 'Alternative Name',
        ]);

        // Query by normalized lowercase alias
        $retrieved = PreacherAlias::where('alias', 'alternative name')->first();

        $this->assertNotNull($retrieved);
        $this->assertEquals($preacher->id, $retrieved->preacher_id);
        $this->assertEquals('alternative name', $retrieved->alias);
    }

    #[Test]
    public function it_casts_id_and_preacher_id_to_integers(): void
    {
        $preacher = Preacher::factory()->create();
        $alias = PreacherAlias::create([
            'preacher_id' => (string) $preacher->id,
            'alias' => 'String ID Test',
        ]);

        $this->assertIsInt($alias->id);
        $this->assertIsInt($alias->preacher_id);
    }

    #[Test]
    public function validation_rules_require_valid_preacher_id(): void
    {
        $rules = PreacherAlias::validationRules();

        $this->assertContains('required', $rules['preacher_id']);
        $this->assertContains('integer', $rules['preacher_id']);
        $this->assertContains('exists:preachers,id', $rules['preacher_id']);
    }

    #[Test]
    public function validation_rules_require_unique_alias(): void
    {
        $preacher = Preacher::factory()->create();
        PreacherAlias::create([
            'preacher_id' => $preacher->id,
            'alias' => 'unique-alias',
        ]);

        $rules = PreacherAlias::validationRules();
        $this->assertContains('required', $rules['alias']);

        // Check for unique rule presence - specific implementation details might vary based on how Laravel constructs the rule object
        $foundUnique = false;
        foreach ($rules['alias'] as $rule) {
            if ($rule instanceof \Illuminate\Validation\Rules\Unique) {
                $foundUnique = true;
                break;
            }
        }
        $this->assertTrue($foundUnique, 'Unique rule should be present in alias validation rules');
    }
}
