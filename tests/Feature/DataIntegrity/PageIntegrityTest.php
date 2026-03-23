<?php

namespace Tests\Feature\DataIntegrity;

use App\Enums\PageArea;
use App\Livewire\Admin\Pages\CreatePage;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PageIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_allows_same_slug_in_different_areas(): void
    {
        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'about',
        ]);

        $page2 = Page::factory()->create([
            'area' => PageArea::COMMUNITY,
            'slug' => 'about',
        ]);

        $this->assertDatabaseHas('pages', [
            'area' => PageArea::CHURCH->value,
            'slug' => 'about',
        ]);
        $this->assertDatabaseHas('pages', [
            'area' => PageArea::COMMUNITY->value,
            'slug' => 'about',
        ]);
    }

    #[Test]
    public function it_prevents_duplicate_slug_in_same_area_at_database_level(): void
    {
        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'about',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        // Bypass Eloquent and validation to test database constraint directly
        \Illuminate\Support\Facades\DB::table('pages')->insert([
            'area' => PageArea::CHURCH->value,
            'slug' => 'about',
            'heading' => 'Another About',
            'description' => 'Desc',
            'body' => 'Body',
            'navigation' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_validates_duplicate_slug_in_same_area_in_livewire(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'about',
        ]);

        Livewire::actingAs($admin)
            ->test(CreatePage::class)
            ->set('form.area', PageArea::CHURCH->value)
            ->set('form.slug', 'about')
            ->set('form.heading', 'New About')
            ->set('form.description', 'Test Description')
            ->call('save')
            ->assertHasErrors(['form.slug' => 'unique']);
    }

    #[Test]
    public function it_allows_same_slug_in_different_area_in_livewire(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Page::factory()->create([
            'area' => PageArea::CHURCH,
            'slug' => 'about',
        ]);

        Livewire::actingAs($admin)
            ->test(CreatePage::class)
            ->set('form.area', PageArea::COMMUNITY->value)
            ->set('form.slug', 'about')
            ->set('form.heading', 'Community About')
            ->set('form.description', 'Test Description')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pages', [
            'area' => PageArea::COMMUNITY->value,
            'slug' => 'about',
        ]);
    }
}
