<?php

declare(strict_types=1);

namespace Tests\Feature\DataIntegrity;

use App\Enums\PageArea;
use App\Livewire\Admin\Pages\CreatePage;
use App\Models\Page;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'area' => PageArea::Church,
            'slug' => 'about',
        ]);

        $page2 = Page::factory()->create([
            'area' => PageArea::Community,
            'slug' => 'about',
        ]);

        $this->assertDatabaseHas('pages', [
            'area' => PageArea::Church->value,
            'slug' => 'about',
        ]);
        $this->assertDatabaseHas('pages', [
            'area' => PageArea::Community->value,
            'slug' => 'about',
        ]);
    }

    #[Test]
    public function it_prevents_duplicate_slug_in_same_area_at_database_level(): void
    {
        Page::factory()->create([
            'area' => PageArea::Church,
            'slug' => 'about',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        // Bypass Eloquent and validation to test database constraint directly
        DB::table('pages')->insert([
            'area' => PageArea::Church->value,
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
            'area' => PageArea::Church,
            'slug' => 'about',
        ]);

        Livewire::actingAs($admin)
            ->test(CreatePage::class)
            ->set('form.area', PageArea::Church->value)
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
            'area' => PageArea::Church,
            'slug' => 'about',
        ]);

        Livewire::actingAs($admin)
            ->test(CreatePage::class)
            ->set('form.area', PageArea::Community->value)
            ->set('form.slug', 'about')
            ->set('form.heading', 'Community About')
            ->set('form.description', 'Test Description')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('pages', [
            'area' => PageArea::Community->value,
            'slug' => 'about',
        ]);
    }
}
