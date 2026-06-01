<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Admin\Pages\EditPage;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminFormUXTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function it_renders_sticky_action_bar_in_admin_forms(): void
    {
        $this->actingAs($this->admin);

        $page = Page::factory()->create([
            'heading' => 'Test Page',
            'slug' => 'test-page',
        ]);

        $response = Livewire::test(EditPage::class, ['page' => $page])
            ->assertStatus(200);

        $html = $response->html();

        // Verify top actions have intersection observer
        $this->assertStringContainsString('x-intersect:enter="topVisible = true"', $html);
        $this->assertStringContainsString('x-intersect:leave="topVisible = false"', $html);

        // Verify sticky footer is present
        $this->assertStringContainsString('x-show="!topVisible"', $html);
        $this->assertStringContainsString('fixed bottom-0 left-0 right-0', $html);
        $this->assertStringContainsString('Unsaved changes on', $html);
        $this->assertStringContainsString('Test Page', $html);
    }
}
