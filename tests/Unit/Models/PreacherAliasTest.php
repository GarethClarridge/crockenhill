<?php

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
        $this->assertEquals($data['alias'], $alias->alias);
    }

    #[Test]
    public function it_belongs_to_a_preacher(): void
    {
        $preacher = Preacher::factory()->create(['name' => 'Mark Drury']);
        $alias = PreacherAlias::create([
            'preacher_id' => $preacher->id,
            'alias' => 'Mark D',
        ]);

        $this->assertInstanceOf(Preacher::class, $alias->preacher);
        $this->assertEquals($preacher->id, $alias->preacher->id);
        $this->assertEquals('Mark Drury', $alias->preacher->name);
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

        $retrieved = PreacherAlias::where('alias', 'Alternative Name')->first();

        $this->assertNotNull($retrieved);
        $this->assertEquals($preacher->id, $retrieved->preacher_id);
        $this->assertEquals('Alternative Name', $retrieved->alias);
    }
}
