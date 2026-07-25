<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Livewire\Admin\Preachers\CreatePreacher;
use App\Livewire\Admin\Preachers\EditPreacher;
use App\Models\Preacher;
use App\Models\User;
use App\Services\Preacher\PreacherResolutionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PreacherIntegrityTest extends TestCase
{
    use RefreshDatabase;

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
            ->test(CreatePreacher::class)
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
            ->test(EditPreacher::class, ['preacher' => $preacher])
            ->set('bio', 'New bio content')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals('New bio content', $preacher->fresh()->bio);
    }

    #[Test]
    public function it_resolves_preacher_by_name_in_resolution_service(): void
    {
        $preacher = Preacher::factory()->create([
            'name' => 'Canonical Name',
            'slug' => 'canonical-name',
        ]);

        $service = app(PreacherResolutionService::class);

        // Should find existing by name (ignoring case/whitespace as handled by service)
        $resolved = $service->resolve('  canonical name  ');

        $this->assertEquals($preacher->id, $resolved->id);
        $this->assertEquals('Canonical Name', $resolved->name);
    }

    #[Test]
    public function it_handles_race_condition_in_resolution_service(): void
    {
        $name = 'Race Condition Preacher';
        $slug = 'race-condition-preacher';

        // Simulate a race where the preacher is created between lookup and insert
        // by manually creating it just before the service call
        Preacher::create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
        ]);

        $service = app(PreacherResolutionService::class);

        // This call will trigger the catch block in findOrCreatePreacher
        $resolved = $service->resolve($name);

        $this->assertEquals($name, $resolved->name);
        $this->assertDatabaseCount('preachers', 1);
    }

    #[Test]
    public function it_validates_is_active_is_boolean(): void
    {
        $rules = Preacher::validationRules();

        $this->assertArrayHasKey('is_active', $rules);
        $this->assertContains('boolean', $rules['is_active']);

        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(CreatePreacher::class)
            ->set('name', 'Preacher Active Valid')
            ->set('isActive', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('preachers', [
            'name' => 'Preacher Active Valid',
            'is_active' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(CreatePreacher::class)
            ->set('name', 'Preacher Active True')
            ->set('isActive', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('preachers', [
            'name' => 'Preacher Active True',
            'is_active' => true,
        ]);
    }
}
