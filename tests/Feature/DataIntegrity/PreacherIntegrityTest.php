<?php

namespace Tests\Feature\DataIntegrity;

use App\Models\Preacher;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_prevents_duplicate_preacher_name_at_database_level(): void
    {
        Preacher::factory()->create(['name' => 'John Duplicate']);

        $this->expectException(QueryException::class);

        Preacher::factory()->create([
            'name' => 'John Duplicate',
            'slug' => 'john-duplicate-2',
        ]);
    }

    #[Test]
    public function it_validates_duplicate_preacher_name_in_livewire(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Preacher::factory()->create(['name' => 'Existing Preacher']);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\Preachers\CreatePreacher::class)
            ->set('name', 'Existing Preacher')
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);
    }

    #[Test]
    public function it_allows_updating_preacher_keeping_same_name(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $preacher = Preacher::factory()->create(['name' => 'Original Name']);

        Livewire::actingAs($admin)
            ->test(\App\Livewire\Admin\Preachers\EditPreacher::class, ['preacher' => $preacher])
            ->set('bio', 'New bio content')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals('New bio content', $preacher->fresh()->bio);
    }
}
